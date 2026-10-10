<?php

namespace App\MailServer\Queries;

use App\MailServer\Models\Domain;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the Access tab: the people with a live grant on a domain (one given under the name the
 * guide row still carries, as DomainAccess counts it). Server admins have every domain, so a grant
 * left on one is neither listed nor counted. A person with an empty name goes by their email.
 *
 * @phpstan-type Person array{id: int, name: string, email: string, role: string, status: string, granted_by_name: string|null, granted_at: string|null}
 */
class DomainPeople
{
    /** How a person is named and sorted in lists: the name, or the email when the name is empty. */
    public const PERSON = "coalesce(nullif(u.name, ''), u.email)";

    /**
     * The people with a grant on $d (deactivated users too, with their status), sorted by name; or
     * only $userId's row.
     *
     * @return list<Person>
     */
    public function list(Domain $d, ?int $userId = null): array
    {
        // granted_by is set null when the granter is deleted, so both columns are null then.
        $query = DB::table('domain_grants as g')
            ->join('users as u', 'u.id', '=', 'g.user_id')
            ->leftJoin('users as b', 'b.id', '=', 'g.granted_by')
            ->where('g.domain_id', $d->id)
            ->whereRaw('cast(lower(g.domain_name) as binary) = ?', [strtolower($d->name)])
            ->where('u.is_server_admin', false)
            ->select('u.id', 'u.name', 'u.email', 'g.role', 'u.status', 'g.created_at as granted_at')
            ->selectRaw("coalesce(nullif(b.name, ''), b.email) as granted_by_name")
            ->orderByRaw(self::PERSON)
            ->orderBy('u.id');
        if ($userId !== null) {
            $query->where('u.id', $userId);
        }

        $people = [];
        foreach ($query->get() as $row) {
            $r = get_object_vars($row);
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
}
