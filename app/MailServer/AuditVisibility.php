<?php

namespace App\MailServer;

use App\Auth\DomainAccess;
use App\Auth\DomainScope;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Which audit rows a user may read; the activity log and the dashboard's recent activity both use it.
 *
 * A server admin reads every row. Anyone else reads a row only when its `domain_name` names a domain in
 * their DomainScope and its action is not admin-only (isAdminOnly()). Rows match domains by name, so a
 * domain deleted and created again under the same name shows its earlier history to the new domain's
 * people (intended). A row with no domain (a server-admin invitation, account changes, the command
 * line) is for admins only.
 */
class AuditVisibility
{
    public function __construct(private readonly DomainAccess $access) {}

    /** @param  Builder<AuditEntry>  $q */
    public function constrain(Builder $q, User $u): void
    {
        $this->constrainToScope($q, $this->access->scope($u));
    }

    /**
     * The same rule from a DomainScope: DomainAccess::scope() is every domain exactly for an active
     * server admin, so a scope that is not "all" is a non-admin's.
     *
     * @param  Builder<AuditEntry>  $q
     */
    public function constrainToScope(Builder $q, DomainScope $scope): void
    {
        if ($scope->isAll()) {
            return;
        }

        // An empty list compiles to `0 = 1`: no rows.
        $q->whereIn('domain_name', $this->domainNames($scope))
            ->where('action', 'not like', 'admin.%')
            ->where('action', 'not like', 'maildir.%')
            ->where(fn (Builder $w) => $w->where('action', 'not like', 'user.%')->orWhere('action', 'user.invited'));
    }

    /** Every `admin.*`, every `maildir.*`, and every `user.*` except `user.invited`. */
    public function isAdminOnly(string $action): bool
    {
        return str_starts_with($action, 'admin.')
            || str_starts_with($action, 'maildir.')
            || (str_starts_with($action, 'user.') && $action !== 'user.invited');
    }

    /** @return list<string> the lowercased names of the domains in a limited $scope */
    private function domainNames(DomainScope $scope): array
    {
        $ids = $scope->ids() ?? [];
        if ($ids === []) {
            return [];
        }

        return array_values(DB::table(GuideSchema::table('virtual_domains'))->whereIn('id', $ids)->pluck('name')
            ->map(fn (mixed $n): string => strtolower(is_scalar($n) ? (string) $n : ''))
            ->all());
    }
}
