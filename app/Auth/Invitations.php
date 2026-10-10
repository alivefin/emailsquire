<?php

namespace App\Auth;

use App\Mail\InvitationMail;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Exceptions\InvitationInvalid;
use App\MailServer\Models\Domain;
use App\Models\DomainGrant;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * The only writer of `invitations` (plan 2c, Task 4): an emailed, single-use link that gives a new or
 * existing console user a `manager` or `viewer` role on one guide domain, or makes them a server admin.
 *
 * The token is 32 random bytes, base64url; only its SHA-256 is stored. A link is valid for 72 hours,
 * and only while its domain still exists under the name it was given for and its inviter is active and
 * may still share that domain (a server admin, for a server-admin invitation). Each write audits in its
 * transaction (object type `invitation`, key the invited email); no row ever holds the token or hash.
 *
 * The mail is queued after the transaction commits (InvitationMail is encrypted on the queue).
 *
 * @phpstan-type PendingInvitation array{id: int, email: string, role: string, invited_by_name: string|null, created_at: string|null, expires_at: string, state: string}
 */
class Invitations
{
    public const TTL_HOURS = 72;

    public function __construct(
        private readonly AuditLog $audit,
        private readonly DomainAccess $access,
        private readonly Grants $grants,
        private readonly AddressNormalizer $normalizer,
    ) {}

    /**
     * Invites $email to $role on $d (a domain role) or to be a server admin ($d null), queues the mail
     * in $locale and audits `user.invited` `{role}`.
     *
     * Refused with 409 `already_has_access` when the email belongs to a server admin, already has a
     * grant on $d or has a valid invitation for the same target, and with 409 `user_deactivated` when
     * it belongs to a deactivated user.
     */
    public function create(string $email, string $role, ?Domain $d, ?string $note, string $locale, ?string $name, User $by): Invitation
    {
        $email = Str::lower(trim($email));
        if (! in_array($role, Invitation::ROLES, true) || ($role === 'server_admin') !== ($d === null)) {
            throw new InvalidArgumentException("Role {$role} does not fit the target.");
        }
        $token = self::newToken();

        // A deadlock between two creates for one address (both hold the gap lock, both insert) is
        // retried; the loser then sees the winner's row and answers 409.
        return DB::transaction(function () use ($email, $role, $d, $note, $locale, $name, $by, $token): Invitation {
            $live = $d === null ? null : $this->liveDomain($d->id, $d->name);

            // Every invitation of this address, and the index gap a new one would go into, so a
            // concurrent create for the same address waits here.
            $earlier = Invitation::query()->where('email', $email)->lockForUpdate()->get();
            $user = User::query()->where('email', $email)->lockForUpdate()->first();
            if ($user !== null) {
                if (! $user->isActive()) {
                    throw new Conflict('user_deactivated', __('emailsquire.invitation.user_deactivated', ['email' => $email]));
                }
                if ($user->is_server_admin) {
                    throw new Conflict('already_has_access', __('emailsquire.access.server_admin'));
                }
                if ($live !== null && $this->grants->find($user, $live) !== null) {
                    throw new Conflict('already_has_access', __('emailsquire.invitation.has_access', ['email' => $email]));
                }
            }
            foreach ($earlier as $other) {
                if ($this->sameTarget($other, $live) && $this->state($other) === 'valid') {
                    throw new Conflict('already_has_access', __('emailsquire.invitation.pending', ['email' => $email]));
                }
            }

            $invitation = new Invitation;
            $invitation->forceFill([
                'token_hash' => hash('sha256', $token),
                'email' => $email,
                'domain_id' => $live?->id,
                'domain_name' => $live?->name,
                'role' => $role,
                'note' => $note,
                'locale' => $locale,
                'name' => $name,
                'invited_by' => $by->id,
                'expires_at' => now()->addHours(self::TTL_HOURS),
            ])->save();

            $this->audit->record('user.invited', 'invitation', $email, $live?->name, ['role' => $role], $by);
            $this->queueMail($invitation, $token, $by);

            return $invitation;
        }, 3);
    }

    /**
     * Sends the invitation again with a new token (the old link stops working), valid for 72 hours
     * from now, in the name of $by, who now answers for it as its inviter. Audits `invitation.resent`.
     * An accepted or revoked invitation, or one whose domain is gone, answers 409 `invitation_closed`.
     */
    public function resend(Invitation $i, User $by): void
    {
        $token = self::newToken();

        DB::transaction(function () use ($i, $by, $token): void {
            $locked = $this->lockOpen($i);
            if ($locked->domain_id !== null && $this->domainOf($locked) === null) {
                throw $this->closed();
            }

            $locked->forceFill([
                'token_hash' => hash('sha256', $token),
                'expires_at' => now()->addHours(self::TTL_HOURS),
                'invited_by' => $by->id,
            ])->save();

            $this->audit->record('invitation.resent', 'invitation', $locked->email, $locked->domain_name, ['role' => $locked->role], $by);
            $this->queueMail($locked, $token, $by);
        });
        $i->refresh();
    }

    /**
     * Revokes a pending (or expired) invitation and audits `invitation.revoked`. An accepted or
     * already revoked one answers 409 `invitation_closed`.
     */
    public function revoke(Invitation $i, User $by): void
    {
        DB::transaction(function () use ($i, $by): void {
            $locked = $this->lockOpen($i);
            $locked->forceFill(['revoked_at' => now()])->save();

            $this->audit->record('invitation.revoked', 'invitation', $locked->email, $locked->domain_name, ['role' => $locked->role], $by);
        });
        $i->refresh();
    }

    /**
     * `used` once accepted; `revoked` when revoked, or when its domain is gone or renamed, or its
     * inviter is gone, deactivated or can no longer share the domain; `expired` after 72 hours;
     * otherwise `valid`.
     *
     * @return 'valid'|'expired'|'used'|'revoked'
     */
    public function state(Invitation $i): string
    {
        return match (true) {
            $i->accepted_at !== null => 'used',
            $i->revoked_at !== null, ! $this->stillAllowed($i) => 'revoked',
            $i->expires_at->isPast() => 'expired',
            default => 'valid',
        };
    }

    /** The invitation whose link carries $token, if any. */
    public function byToken(string $token): ?Invitation
    {
        // A token is 43 base64url characters; anything else matches nothing, without a query.
        if (preg_match('/^[A-Za-z0-9_-]{43}$/', $token) !== 1) {
            return null;
        }

        return Invitation::query()->where('token_hash', hash('sha256', $token))->first();
    }

    /**
     * Accepts a valid invitation and returns the user it is for.
     *
     * An existing user must be $signedIn (409 `sign_in_required`, or `wrong_account` when someone
     * else is); a new person needs $newUser and becomes an active user in the invitation's language.
     * A domain role is granted through Grants with the inviter as granter; a server-admin invitation
     * sets `is_server_admin`, clears the user's grants and audits `user.role_changed`. Audits
     * `invitation.accepted` `{role}` with the invitee as actor. Signs nobody in.
     *
     * Locks, in this order: the guide domain row shared (as DomainRepository::delete takes it before it
     * revokes the domain's invitations), the invitation row FOR UPDATE, then the user row.
     *
     * @param  array{name: string, password: string}|null  $newUser
     *
     * @throws InvitationInvalid when the invitation is not valid (410)
     */
    public function accept(Invitation $i, ?User $signedIn, ?array $newUser): User
    {
        return DB::transaction(function () use ($i, $signedIn, $newUser): User {
            if ($i->domain_id !== null) {
                Domain::query()->whereKey($i->domain_id)->sharedLock()->first();
            }
            $locked = Invitation::query()->whereKey($i->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(Invitation::class);
            $state = $this->state($locked);
            if ($state !== 'valid') {
                throw new InvitationInvalid($state);
            }
            // A valid invitation has a live inviter (stillAllowed()).
            $inviter = User::query()->findOrFail($locked->invited_by);

            $user = $this->invitee($locked, $signedIn, $newUser);

            if ($locked->role === 'server_admin') {
                $this->promote($user);
            } elseif (! $user->is_server_admin) {
                $domain = Domain::query()->findOrFail($locked->domain_id);
                $this->grants->put($user, $domain, $locked->role, $inviter, $user);
            }

            $locked->forceFill(['accepted_at' => now()])->save();
            $this->audit->record('invitation.accepted', 'invitation', $locked->email, $locked->domain_name, ['role' => $locked->role], $user);

            return $user;
        });
    }

    /**
     * The domain's invitations that are neither accepted nor revoked by hand, sorted by email, with
     * their state (`valid`, `expired`, or `revoked` when their inviter can no longer share).
     *
     * @return list<PendingInvitation>
     */
    public function pendingFor(Domain $d): array
    {
        $rows = Invitation::query()
            ->where('domain_id', $d->id)
            ->whereNull('accepted_at')
            ->whereNull('revoked_at')
            ->with('inviter')
            ->orderBy('email')
            ->orderBy('id')
            ->get();

        $pending = [];
        foreach ($rows as $invitation) {
            if (strtolower((string) $invitation->domain_name) !== strtolower($d->name)) {
                continue;
            }
            $inviter = $invitation->inviter;
            $pending[] = [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'invited_by_name' => $inviter === null ? null : self::nameOf($inviter),
                'created_at' => $invitation->created_at?->toIso8601String(),
                'expires_at' => $invitation->expires_at->toIso8601String(),
                'state' => $this->state($invitation),
            ];
        }

        return $pending;
    }

    /** The domain's unicode name, for mail and the accept page; the stored name when it isn't valid. */
    public function unicode(string $ascii): string
    {
        try {
            return $this->normalizer->domain($ascii)->unicode;
        } catch (InvalidAddress) {
            return $ascii;
        }
    }

    /** How a person is named to others: their name, or their email when the name is empty. */
    public static function nameOf(User $u): string
    {
        return $u->name !== '' ? $u->name : $u->email;
    }

    /**
     * The user who accepts: the signed-in owner of the invited email, or a new user.
     *
     * @param  array{name: string, password: string}|null  $newUser
     */
    private function invitee(Invitation $i, ?User $signedIn, ?array $newUser): User
    {
        $existing = User::query()->where('email', $i->email)->lockForUpdate()->first();
        if ($existing !== null) {
            if ($signedIn === null || ! $existing->isActive()) {
                throw new Conflict('sign_in_required', __('emailsquire.invitation.sign_in_required', ['email' => $i->email]));
            }
            if ($signedIn->id !== $existing->id) {
                throw new Conflict('wrong_account', __('emailsquire.invitation.wrong_account', ['email' => $i->email]));
            }

            return $existing;
        }

        if ($signedIn !== null) {
            throw new Conflict('wrong_account', __('emailsquire.invitation.wrong_account', ['email' => $i->email]));
        }
        if ($newUser === null) {
            // The account the caller saw a moment ago is gone: they need to choose a name and password.
            throw ValidationException::withMessages(['name' => [__('validation.required', ['attribute' => 'name'])]]);
        }

        $user = new User;
        $user->forceFill([
            'name' => $newUser['name'],
            'email' => $i->email,
            'password' => $newUser['password'],
            'status' => User::STATUS_ACTIVE,
            'locale' => $i->locale === 'fi' ? 'fi' : 'en',
            'password_set_at' => now(),
            'is_server_admin' => false,
        ])->save();

        return $user;
    }

    /**
     * Makes $user a server admin. A server admin has every domain, so grants they held are removed
     * (they would come back unasked if the role were ever taken away).
     */
    private function promote(User $user): void
    {
        if ($user->is_server_admin) {
            return;
        }

        $removed = DomainGrant::query()->where('user_id', $user->id)->delete();
        $user->forceFill(['is_server_admin' => true])->save();
        $this->access->forget($user);

        $changes = ['is_server_admin' => [false, true]];
        if ($removed > 0) {
            $changes['grants_removed'] = $removed;
        }
        $this->audit->record('user.role_changed', 'user', $user->email, null, $changes, $user);
    }

    /** The invitation locked FOR UPDATE, or 409 `invitation_closed` when accepted or revoked. */
    private function lockOpen(Invitation $i): Invitation
    {
        $locked = Invitation::query()->whereKey($i->id)->lockForUpdate()->first()
            ?? throw (new ModelNotFoundException)->setModel(Invitation::class);
        if ($locked->accepted_at !== null || $locked->revoked_at !== null) {
            throw $this->closed();
        }

        return $locked;
    }

    private function closed(): Conflict
    {
        return new Conflict('invitation_closed', __('emailsquire.invitation.closed'));
    }

    /** The guide domain at $id, share-locked, while it still carries $name; otherwise 404. */
    private function liveDomain(int $id, string $name): Domain
    {
        $live = Domain::query()->whereKey($id)->sharedLock()->first();
        if ($live === null || strtolower($live->name) !== strtolower($name)) {
            throw (new ModelNotFoundException)->setModel(Domain::class);
        }

        return $live;
    }

    /** The domain an invitation is for, while the guide row at its id still carries its name. */
    private function domainOf(Invitation $i): ?Domain
    {
        if ($i->domain_id === null || $i->domain_name === null) {
            return null;
        }
        $domain = Domain::query()->find($i->domain_id);

        return $domain !== null && strtolower($domain->name) === strtolower($i->domain_name) ? $domain : null;
    }

    /**
     * Whether the invitation's target and inviter still hold: the domain exists under its name, and
     * the inviter exists, is active and may still share it (is still a server admin, for a
     * server-admin invitation). The inviter's grants are read afresh; DomainAccess keeps them per user,
     * so this leaves what the request knows about anyone else (the signed-in user) as it was.
     */
    private function stillAllowed(Invitation $i): bool
    {
        $inviter = $i->invited_by === null ? null : User::query()->find($i->invited_by);
        if ($inviter === null || ! $inviter->isActive()) {
            return false;
        }
        $this->access->forget($inviter);

        if ($i->domain_id === null) {
            return $this->access->isServerAdmin($inviter);
        }
        $domain = $this->domainOf($i);

        return $domain !== null && $this->access->canShare($inviter, $domain);
    }

    private function sameTarget(Invitation $i, ?Domain $live): bool
    {
        if ($live === null) {
            return $i->domain_id === null;
        }

        return $i->domain_id === $live->id && strtolower((string) $i->domain_name) === strtolower($live->name);
    }

    private function queueMail(Invitation $i, string $token, User $inviter): void
    {
        $mail = new InvitationMail(
            url: rtrim(config()->string('app.url'), '/').'/invite/'.$token,
            inviterName: self::nameOf($inviter),
            recipientName: $i->name,
            role: $i->role,
            domainUnicode: $i->domain_name === null ? null : $this->unicode($i->domain_name),
            note: $i->note,
        );

        Mail::to($i->email)->locale($i->locale)->queue($mail->afterCommit());
    }

    /** 32 random bytes, base64url without padding: 43 characters. */
    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
}
