<?php

namespace App\Auth;

use App\Mail\Notify;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\Models\DomainGrant;
use App\Models\PinnedDomain;
use App\Models\User;
use Closure;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

/**
 * The Users screen's writes (plan 2c, Task 5): a server admin changes another console user's name,
 * email, server-admin role or status, deletes them, or resets their two-factor. Each runs in one
 * transaction with its audit rows (object type `user`, key the user's email).
 *
 * Guards: nobody demotes, deactivates, deletes or resets the two-factor of themselves here (409
 * `cannot_change_self`), and the last active server admin is never demoted, deactivated or deleted
 * (409 `last_server_admin`).
 *
 * Locks, in this order: every active server admin's `users` row FOR UPDATE in id order, then the
 * target's row (already held when the target is one of them), then what hangs off the user (grants,
 * pins, sessions). Two admins demoting each other at the same moment take the same rows in the same
 * order: the second waits for the first to commit, then counts one active admin and is refused. Grant
 * writes (Grants) hold one user row and then grant rows; they never wait for a second user row, so they
 * cannot close a cycle with this. The admin rows are read with an index-less filter, so under
 * REPEATABLE READ InnoDB locks every row it scans, still in primary-key order: the console's users
 * table is small, and these writes are rare.
 *
 * @phpstan-type Changes array{name?: string, email?: string, is_server_admin?: bool, status?: 'active'|'deactivated'}
 */
class ConsoleUsers
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly DomainAccess $access,
        private readonly Grants $grants,
        private readonly TwoFactorAccount $twoFactor,
    ) {}

    /**
     * Applies $changes to $target as $actor and returns the user as stored. A field that keeps its value
     * writes nothing. Audits `user.email_changed` `{email: [old, new]}` (and queues the `email_changed`
     * notice to the old address), `user.role_changed` `{is_server_admin: [old, new]}`, `user.deactivated`
     * and `user.reactivated`; a name is not audited. A role change or a deactivation ends all the
     * target's sessions. Becoming a server admin removes the target's grants (`grant.removed` each);
     * losing the role drops their pins of domains they have no grant on, which they can no longer see.
     *
     * @param  Changes  $changes
     */
    public function update(User $target, array $changes, User $actor): User
    {
        if ($target->id === $actor->id && (($changes['is_server_admin'] ?? true) === false || ($changes['status'] ?? null) === User::STATUS_DEACTIVATED)) {
            throw $this->cannotChangeSelf();
        }

        $oldEmail = null;
        $user = $this->locked($target, $actor, function (User $locked, int $activeAdmins) use ($changes, &$oldEmail): User {
            $wasAdmin = $locked->is_server_admin;
            $wasActive = $locked->isActive();
            $admin = $changes['is_server_admin'] ?? $wasAdmin;
            $active = ($changes['status'] ?? $locked->status) === User::STATUS_ACTIVE;
            if ($wasAdmin && $wasActive && ! ($admin && $active)) {
                $this->keepAnAdmin($activeAdmins);
            }

            if (isset($changes['name'])) {
                $locked->name = $changes['name'];
            }
            if (isset($changes['email']) && $changes['email'] !== $locked->email) {
                $oldEmail = $locked->email;
                $this->changeEmail($locked, $changes['email']);
            }
            $endSessions = false;
            if ($admin !== $wasAdmin) {
                $this->changeRole($locked, $admin);
                $endSessions = true;
            }
            if ($active !== $wasActive) {
                $locked->forceFill(['status' => $active ? User::STATUS_ACTIVE : User::STATUS_DEACTIVATED])->save();
                $this->audit->record($active ? 'user.reactivated' : 'user.deactivated', 'user', $locked->email);
                $endSessions = $endSessions || ! $active;
            }
            $locked->save();
            if ($endSessions) {
                OtherSessions::end($locked, null);
            }

            return $locked;
        });

        if ($oldEmail !== null) {
            Notify::security($user, 'email_changed', to: $oldEmail);
        }

        return $user;
    }

    /**
     * Deletes $target as $actor: their grants and pins go with the row (foreign keys), their sessions and
     * any reset or setup link for their email are deleted, and invitations they sent lose their inviter
     * (so they read as revoked). Audits `user.deleted`; the audit rows they wrote stay, naming them by id.
     */
    public function delete(User $target, User $actor): void
    {
        if ($target->id === $actor->id) {
            throw $this->cannotChangeSelf();
        }

        $this->locked($target, $actor, function (User $locked, int $activeAdmins): void {
            if ($locked->is_server_admin && $locked->isActive()) {
                $this->keepAnAdmin($activeAdmins);
            }

            $this->audit->record('user.deleted', 'user', $locked->email);
            $this->forgetLinks($locked);
            OtherSessions::end($locked, null);
            $locked->delete();
        });
        $this->access->forget($target);
    }

    /**
     * Turns $target's confirmed two-factor off as $actor (TwoFactorAccount::reset(): audited as
     * `user.two_factor_reset`, with the security notice). 409 `two_factor_not_enabled` when there is no
     * confirmed two-factor to reset.
     */
    public function resetTwoFactor(User $target, User $actor): void
    {
        if ($target->id === $actor->id) {
            throw $this->cannotChangeSelf();
        }
        if (! $this->twoFactor->reset($target)) {
            throw new Conflict('two_factor_not_enabled', __('emailsquire.two_factor.not_enabled'));
        }
    }

    /**
     * Runs $fn in a transaction with the guard's locks (see the class comment), passing the locked target
     * and how many active server admins there are. An actor who is no longer an active server admin by
     * then (demoted or deactivated by a request that committed first) gets the policy's 404. A deadlock
     * is retried; the retry sees the other side's change.
     *
     * @template T
     *
     * @param  Closure(User, int): T  $fn
     * @return T
     */
    private function locked(User $target, User $actor, Closure $fn): mixed
    {
        return DB::transaction(function () use ($target, $actor, $fn) {
            $admins = User::query()
                ->where('is_server_admin', true)
                ->where('status', User::STATUS_ACTIVE)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn (mixed $id): int => is_numeric($id) ? (int) $id : 0)
                ->all();
            $locked = User::query()->whereKey($target->id)->lockForUpdate()->first()
                ?? throw (new ModelNotFoundException)->setModel(User::class);

            $result = $fn($locked, count($admins));
            // After the guards, so a refused change answers why even to an actor demoted meanwhile.
            if (! in_array($actor->id, $admins, true)) {
                throw (new ModelNotFoundException)->setModel(User::class);
            }
            $this->access->forget($locked);

            return $result;
        }, 3);
    }

    private function keepAnAdmin(int $activeAdmins): void
    {
        if ($activeAdmins <= 1) {
            throw new Conflict('last_server_admin', __('emailsquire.users.last_server_admin'));
        }
    }

    private function changeEmail(User $locked, string $email): void
    {
        $old = $locked->email;
        // A link sent to the old address must not reset the account, nor any later one with that address.
        $this->forgetLinks($locked);
        try {
            $locked->forceFill(['email' => $email])->save();
        } catch (UniqueConstraintViolationException) {
            // Taken by a change that committed after validation.
            throw ValidationException::withMessages(['email' => [__('validation.unique', ['attribute' => 'email'])]]);
        }
        $this->audit->record('user.email_changed', 'user', $email, null, ['email' => [$old, $email]]);
    }

    private function changeRole(User $locked, bool $admin): void
    {
        $changes = ['is_server_admin' => [! $admin, $admin]];
        if ($admin) {
            $removed = $this->grants->removeAllForPromotion($locked);
            if ($removed > 0) {
                $changes['grants_removed'] = $removed;
            }
        } else {
            // A pin of a domain they no longer see would count toward their pin limit, and can't be undone.
            PinnedDomain::query()
                ->where('user_id', $locked->id)
                ->whereNotIn('domain_id', DomainGrant::query()->where('user_id', $locked->id)->select('domain_id'))
                ->delete();
        }
        $locked->forceFill(['is_server_admin' => $admin])->save();
        $this->audit->record('user.role_changed', 'user', $locked->email, null, $changes);
    }

    /** Deletes the forgotten-password and setup tokens of the user's current email. */
    private function forgetLinks(User $user): void
    {
        foreach (['users', 'admin_setup'] as $name) {
            $broker = Password::broker($name);
            if ($broker instanceof PasswordBroker) {
                $broker->deleteToken($user);
            }
        }
    }

    private function cannotChangeSelf(): Conflict
    {
        return new Conflict('cannot_change_self', __('emailsquire.users.cannot_change_self'));
    }
}
