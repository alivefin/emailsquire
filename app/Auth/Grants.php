<?php

namespace App\Auth;

use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Models\Domain;
use App\Models\DomainGrant;
use App\Models\PinnedDomain;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use LogicException;

/**
 * The only writer of `domain_grants`: a user's `manager` or `viewer` role on one guide domain. Each
 * write runs in one transaction with its audit row (object type `user`, key the user's email, the
 * domain's name). A server admin has every domain already and gets no grant (409 `already_has_access`).
 *
 * Locks, in this order: the guide domain row shared (a concurrent domain delete either went first, a
 * 404, or waits and then removes the grant with the domain), the target user row FOR UPDATE (one grant
 * change per user at a time, and a fresh `is_server_admin`), then the grant row.
 *
 * A grant counts only while the guide row at `domain_id` still carries `domain_name` (DomainAccess);
 * a row left from another name is replaced as a new grant.
 *
 * @phpstan-type Person array{id: int, name: string, email: string, role: string, status: string, granted_by_name: string|null, granted_at: string|null}
 */
class Grants
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly DomainAccess $access,
    ) {}

    /**
     * Gives $user $role on $d, or changes the role they have. Audits `grant.created` `{role}` or
     * `grant.changed` `{role: [old, new]}`; the same role again writes nothing.
     *
     * @return 'created'|'changed'|'unchanged'
     */
    public function put(User $user, Domain $d, string $role, User $by): string
    {
        return $this->write($user, $d, $role, $by);
    }

    /**
     * Changes the role of a user who has a grant on $d; a grant gone meanwhile is a 404 rather than
     * a new grant (PATCH /domains/{d}/access/{user}).
     *
     * @return 'changed'|'unchanged'
     */
    public function change(User $user, Domain $d, string $role): string
    {
        $result = $this->write($user, $d, $role, null);

        return $result === 'created' ? throw new LogicException('change() never creates a grant.') : $result;
    }

    /**
     * Removes $user's grant on $d, and their pins of $d with it (a pin on a domain they can no longer
     * see would still count toward their pin limit). Audits `grant.removed` `{role}`. False when there
     * was no grant.
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

            return true;
        });
        $this->access->forget($user);

        return $removed;
    }

    /** The user's live grant on $d: one given under the name the domain still has. */
    public function find(User $user, Domain $d): ?DomainGrant
    {
        $grant = DomainGrant::query()->where('user_id', $user->id)->where('domain_id', $d->id)->first();

        return $grant !== null && $this->live($grant, $d) ? $grant : null;
    }

    /**
     * The people with a grant on $d, sorted by name (or only $userId's row): deactivated users too,
     * with their status.
     *
     * @return list<Person>
     */
    public function people(Domain $d, ?int $userId = null): array
    {
        $query = DB::table('domain_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->leftJoin('users as b', 'b.id', '=', 'g.granted_by')
            ->where('g.domain_id', $d->id)
            ->orderBy('u.name')->orderBy('u.email')->orderBy('u.id')
            ->select('u.id', 'u.name', 'u.email', 'g.role', 'u.status', 'b.name as granted_by_name', 'g.created_at as granted_at', 'g.domain_name');
        if ($userId !== null) {
            $query->where('u.id', $userId);
        }

        $people = [];
        foreach ($query->get() as $row) {
            $r = get_object_vars($row);
            if (! is_string($r['domain_name']) || strtolower($r['domain_name']) !== strtolower($d->name)) {
                continue; // given under a name the guide row no longer carries
            }
            $people[] = [
                'id' => is_numeric($r['id']) ? (int) $r['id'] : 0,
                'name' => is_string($r['name']) ? $r['name'] : '',
                'email' => is_string($r['email']) ? $r['email'] : '',
                'role' => is_string($r['role']) ? $r['role'] : '',
                'status' => is_string($r['status']) ? $r['status'] : '',
                'granted_by_name' => is_string($r['granted_by_name']) ? $r['granted_by_name'] : null,
                'granted_at' => is_string($r['granted_at']) ? Carbon::parse($r['granted_at'])->toIso8601String() : null,
            ];
        }

        return $people;
    }

    /**
     * put() with $by, or change() with null (which needs a grant to be there).
     *
     * @return 'created'|'changed'|'unchanged'
     */
    private function write(User $user, Domain $d, string $role, ?User $by): string
    {
        if (! in_array($role, DomainGrant::ROLES, true)) {
            throw new InvalidArgumentException("Unknown grant role {$role}.");
        }

        $result = $this->locked($user, $d, function (User $target, Domain $live, ?DomainGrant $grant, ?DomainGrant $stale) use ($role, $by): string {
            if ($grant !== null) {
                $old = $grant->role;
                if ($old === $role) {
                    return 'unchanged';
                }
                $grant->forceFill(['role' => $role])->save();
                $this->audit->record('grant.changed', 'user', $target->email, $live->name, ['role' => [$old, $role]]);

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
            $this->audit->record('grant.created', 'user', $target->email, $live->name, ['role' => $role]);

            return 'created';
        });
        $this->access->forget($user);

        return $result;
    }

    /**
     * Runs $fn in a transaction with the locks taken, passing the locked user, the live domain, the
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
            if ($target->is_server_admin) {
                throw new Conflict('already_has_access', __('emailsquire.access.server_admin'));
            }

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
