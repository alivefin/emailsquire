<?php

namespace App\Auth;

use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\DomainPeople;
use App\Models\DomainGrant;
use App\Models\PinnedDomain;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of `domain_grants`: a user's `manager` or `viewer` role on one guide domain. Each
 * write runs in one transaction with its audit row (object type `user`, key the user's email, the
 * domain's name). A server admin has every domain already: put() and change() refuse one (409
 * `already_has_access`), remove() takes away a grant left on one, and removeAllForPromotion() clears
 * a user's grants as they become one.
 *
 * Locks, in this order: the guide domain row shared (a concurrent domain delete either went first, a
 * 404, or waits and then removes the grant with the domain), the target user row FOR UPDATE (one grant
 * change per user at a time, and a fresh `is_server_admin`), the grant row, then (when a manager loses
 * the role) the invitations they sent to the domain.
 *
 * A grant counts only while the guide row at `domain_id` still carries `domain_name` (DomainAccess);
 * a row left from another name is replaced as a new grant.
 *
 * @phpstan-import-type Person from DomainPeople
 */
class Grants
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly DomainAccess $access,
        private readonly DomainPeople $people,
    ) {}

    /**
     * Gives $user $role on $d, or changes the role they have. Audits `grant.created` `{role}` or
     * `grant.changed` `{role: [old, new]}`; the same role again writes nothing. $actor names who
     * acted in the audit row when it is not the signed-in user (an invitee accepting, Invitations).
     *
     * @return 'created'|'changed'|'unchanged'
     */
    public function put(User $user, Domain $d, string $role, User $by, ?User $actor = null): string
    {
        return $this->write($user, $d, $role, $by, $actor);
    }

    /**
     * Changes the role of a user who has a grant on $d and returns them as written; a grant gone
     * meanwhile is a 404 rather than a new grant (PATCH /domains/{d}/access/{user}). The person is
     * read before the locks are released, so a removal right after cannot turn it into a 404.
     *
     * @return Person
     */
    public function change(User $user, Domain $d, string $role): array
    {
        // The inner transaction is a savepoint: its locks are held until this one commits.
        return DB::transaction(function () use ($user, $d, $role): array {
            $result = $this->write($user, $d, $role, null);
            if ($result === 'created') {
                throw new LogicException('change() never creates a grant.');
            }

            return $this->people->list($d, $user->id)[0] ?? throw new LogicException('The changed grant is not listed.');
        });
    }

    /**
     * Removes $user's grant on $d, and their pins of $d with it (a pin on a domain they can no longer
     * see would still count toward their pin limit). Audits `grant.removed` `{role}`. A manager's
     * invitations to $d are revoked with it (Invitations::revokeSentBy()), as when put() or change()
     * lowers them to viewer. False when there was no grant.
     */
    public function remove(User $user, Domain $d): bool
    {
        $removed = $this->locked($user, $d, function (User $target, Domain $live, ?DomainGrant $grant): bool {
            if ($grant === null) {
                return false;
            }

            $grant->delete();
            PinnedDomain::query()->where('user_id', $target->id)->where('domain_id', $live->id)->delete();
            $this->audit->record('grant.removed', 'user', $target->email, $live->name, ['role' => $grant->role]);
            if ($grant->role === 'manager') {
                $this->invitations()->revokeSentBy($target, $live, null);
            }

            return true;
        });
        $this->access->forget($user);

        return $removed;
    }

    /**
     * Removes every grant of a user who is becoming a server admin (who has every domain, so a grant
     * kept would come back unasked if the role were taken away) and audits `grant.removed` `{role}` for
     * each, with the name it was given under. Their pins stay: a server admin still sees those domains.
     * Answers how many grants it removed.
     *
     * The caller holds $user's row FOR UPDATE in its transaction (the promotion itself); this takes the
     * grant rows after it, as every other grant write does, and no guide row.
     */
    public function removeAllForPromotion(User $user): int
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('removeAllForPromotion() runs inside the promotion\'s transaction.');
        }

        $grants = DomainGrant::query()->where('user_id', $user->id)->orderBy('domain_name')->orderBy('id')->lockForUpdate()->get();
        foreach ($grants as $grant) {
            $grant->delete();
            $this->audit->record('grant.removed', 'user', $user->email, $grant->domain_name, ['role' => $grant->role]);
        }
        $this->access->forget($user);

        return $grants->count();
    }

    /**
     * Invitations, resolved when needed: it takes Grants in its constructor, so Grants can't take it in
     * its own.
     */
    private function invitations(): Invitations
    {
        return app(Invitations::class);
    }

    /** The user's live grant on $d: one given under the name the domain still has. */
    public function find(User $user, Domain $d): ?DomainGrant
    {
        $grant = DomainGrant::query()->where('user_id', $user->id)->where('domain_id', $d->id)->first();

        return $grant !== null && $this->live($grant, $d) ? $grant : null;
    }

    /**
     * put() with $by, or change() with null (which needs a grant to be there).
     *
     * @return 'created'|'changed'|'unchanged'
     */
    private function write(User $user, Domain $d, string $role, ?User $by, ?User $actor = null): string
    {
        if (! in_array($role, DomainGrant::ROLES, true)) {
            throw new InvalidArgumentException("Unknown grant role {$role}.");
        }

        $result = $this->locked($user, $d, function (User $target, Domain $live, ?DomainGrant $grant, ?DomainGrant $stale) use ($role, $by, $actor): string {
            if ($target->is_server_admin) {
                throw new Conflict('already_has_access', __('emailsquire.access.server_admin'));
            }
            if ($grant !== null) {
                $old = $grant->role;
                if ($old === $role) {
                    return 'unchanged';
                }
                $grant->forceFill(['role' => $role])->save();
                $this->audit->record('grant.changed', 'user', $target->email, $live->name, ['role' => [$old, $role]], $actor);
                if ($old === 'manager') {
                    $this->invitations()->revokeSentBy($target, $live, null);
                }

                return 'changed';
            }
            if ($by === null) {
                throw (new ModelNotFoundException)->setModel(DomainGrant::class);
            }

            // A row left from a domain renamed outside EmailSquire holds the unique (user, domain id).
            $grant = $stale ?? new DomainGrant;
            $grant->forceFill([
                'user_id' => $target->id,
                'domain_id' => $live->id,
                'domain_name' => $live->name,
                'role' => $role,
                'granted_by' => $by->id,
                'created_at' => now(),
            ])->save();
            $this->audit->record('grant.created', 'user', $target->email, $live->name, ['role' => $role], $actor);

            return 'created';
        });
        $this->access->forget($user);

        return $result;
    }

    /**
     * Runs $fn in a transaction with the locks taken, passing the locked user (a server admin
     * included; the caller decides), the live domain, the
     * live grant (or null) and a grant row left from another name (or null).
     *
     * @template T
     *
     * @param  Closure(User, Domain, ?DomainGrant, ?DomainGrant): T  $fn
     * @return T
     */
    private function locked(User $user, Domain $d, Closure $fn): mixed
    {
        return DB::transaction(function () use ($user, $d, $fn) {
            $live = Domain::query()->whereKey($d->id)->sharedLock()->first();
            if ($live === null || strtolower($live->name) !== strtolower($d->name)) {
                throw (new ModelNotFoundException)->setModel(Domain::class);
            }

            $target = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $grant = DomainGrant::query()->where('user_id', $target->id)->where('domain_id', $live->id)->lockForUpdate()->first();
            if ($grant !== null && ! $this->live($grant, $live)) {
                return $fn($target, $live, null, $grant);
            }

            return $fn($target, $live, $grant, null);
        });
    }

    private function live(DomainGrant $grant, Domain $d): bool
    {
        return strtolower($grant->domain_name) === strtolower($d->name);
    }
}
