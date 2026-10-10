<?php

namespace App\Auth;

use App\MailServer\Models\Domain;
use App\Models\DomainGrant;
use App\Models\User;

/**
 * The one source of a console user's role on a guide domain (backend plan §6). A server admin is
 * `admin` on every domain; anyone else has the role their `domain_grants` row gives, or none. A user
 * who is not active has no role anywhere.
 *
 * Bound `scoped`: the grants are read once per request and forgotten when it ends (AppServiceProvider),
 * so a grant removed between two requests is gone on the second one.
 */
class DomainAccess
{
    /** @var array<int, array<int, 'manager'|'viewer'>> user id => (domain id => role) */
    private array $grants = [];

    public function isServerAdmin(User $u): bool
    {
        return $u->is_server_admin && $u->isActive();
    }

    /** @return 'admin'|'manager'|'viewer'|null */
    public function role(User $u, Domain $d): ?string
    {
        return $this->roleForId($u, $d->id);
    }

    /** @return 'admin'|'manager'|'viewer'|null by guide `virtual_domains.id` */
    public function roleForId(User $u, int $domainId): ?string
    {
        if (! $u->isActive()) {
            return null;
        }

        return $u->is_server_admin ? 'admin' : ($this->grants($u)[$domainId] ?? null);
    }

    public function scope(User $u): DomainScope
    {
        if (! $u->isActive()) {
            return DomainScope::only([]);
        }

        return $u->is_server_admin ? DomainScope::all() : DomainScope::only(array_keys($this->grants($u)));
    }

    /** Mailbox and forwarding writes: admins and managers. */
    public function canWrite(User $u, Domain $d): bool
    {
        return in_array($this->role($u, $d), ['admin', 'manager'], true);
    }

    /** Grants and invitations: admins, and managers while `emailsquire.managers_can_share` is on. */
    public function canShare(User $u, Domain $d): bool
    {
        return match ($this->role($u, $d)) {
            'admin' => true,
            'manager' => config()->boolean('emailsquire.managers_can_share'),
            default => false,
        };
    }

    /** Drops what this request read of $u's grants, so a grant written meanwhile (Grants) is seen. */
    public function forget(User $u): void
    {
        unset($this->grants[$u->id]);
    }

    /**
     * The user's grants that still point at the domain they were given on: a grant whose guide row is
     * gone, or now carries another name (an id reused outside the console), grants nothing.
     *
     * @return array<int, 'manager'|'viewer'>
     */
    private function grants(User $u): array
    {
        if (isset($this->grants[$u->id])) {
            return $this->grants[$u->id];
        }

        $rows = DomainGrant::query()->where('user_id', $u->id)->get(['domain_id', 'domain_name', 'role']);
        $names = [];
        if ($rows->isNotEmpty()) {
            foreach (Domain::query()->whereIn('id', $rows->pluck('domain_id')->all())->get(['id', 'name']) as $domain) {
                $names[$domain->id] = strtolower($domain->name);
            }
        }

        $grants = [];
        foreach ($rows as $grant) {
            if (($names[$grant->domain_id] ?? null) === strtolower($grant->domain_name) && in_array($grant->role, DomainGrant::ROLES, true)) {
                $grants[$grant->domain_id] = $grant->role;
            }
        }

        return $this->grants[$u->id] = $grants;
    }
}
