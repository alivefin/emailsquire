<?php

namespace App\MailServer\Queries;

use App\MailServer\AuditActions;
use App\MailServer\AuditVisibility;
use App\Models\AuditEntry;
use App\Models\User;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Read side of the activity log (the writer is App\MailServer\AuditLog::record()): the audit rows a user may see (AuditVisibility), filtered, newest
 * first. Every filter narrows what visibility already allows, so a filter naming another domain or
 * someone who acted only elsewhere gives an empty page, the same as a filter that matches nothing.
 */
class AuditLog
{
    public function __construct(private readonly AuditVisibility $visibility) {}

    /**
     * Filters: `domain` (ASCII name, any case), `actor` (a user id, `cli` or `system`), `action_group`
     * (an AuditActions group), `from` (inclusive), `to` (exclusive) and `before_id` (the paging
     * anchor: rows with an id up to it, so rows written after the first page don't shift the others).
     *
     * @param  array{domain?: string|null, actor?: string|null, action_group?: string|null, from?: DateTimeInterface|null, to?: DateTimeInterface|null, before_id?: int|null}  $filters
     * @return LengthAwarePaginator<int, AuditEntry>
     */
    public function paginate(User $u, array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = $this->visible($u);

        if (isset($filters['domain'])) {
            $query->where('domain_name', strtolower($filters['domain']));
        }
        if (isset($filters['actor'])) {
            match ($filters['actor']) {
                'cli', 'system' => $query->where('actor_type', $filters['actor']),
                default => $query->where('actor_type', 'user')->where('actor_id', $filters['actor']),
            };
        }
        if (isset($filters['action_group'])) {
            $query->whereIn('action', AuditActions::group($filters['action_group']));
        }
        if (isset($filters['from'])) {
            $query->where('occurred_at', '>=', $this->stored($filters['from']));
        }
        if (isset($filters['to'])) {
            $query->where('occurred_at', '<', $this->stored($filters['to']));
        }
        if (isset($filters['before_id'])) {
            $query->where('id', '<=', $filters['before_id']);
        }

        return $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate($perPage, ['*'], 'page', $page);
    }

    /**
     * The distinct actors of the rows $u may see, for the actor filter: people by name, then deleted
     * users (by their old id), then the command line and the system. `key` is the filter's value. A
     * deleted user's and the non-people's names are English labels; the console words the kinds itself.
     *
     * @return list<array{key: string, kind: 'user'|'deleted_user'|'cli'|'system', name: string}>
     */
    public function actors(User $u): array
    {
        $pairs = $this->visible($u)->toBase()->distinct()->get(['actor_type', 'actor_id']);

        $ids = [];
        $kinds = [];
        foreach ($pairs as $pair) {
            $type = $pair->actor_type ?? null;
            $id = $pair->actor_id ?? null;
            if ($type === 'user') {
                if (is_string($id) && ctype_digit($id)) {
                    $ids[(int) $id] = true;
                }
            } elseif ($type === 'cli') {
                $kinds['cli'] = true;
            } else {
                $kinds['system'] = true;
            }
        }

        $people = User::query()->whereIn('id', array_keys($ids))->get(['id', 'name'])
            ->sortBy(fn (User $p): string => mb_strtolower($p->name), SORT_NATURAL);

        $actors = [];
        foreach ($people as $person) {
            $actors[] = ['key' => (string) $person->id, 'kind' => 'user', 'name' => $person->name];
            unset($ids[$person->id]);
        }
        // What is left acted as a user who no longer exists.
        $deleted = array_keys($ids);
        sort($deleted);
        foreach ($deleted as $id) {
            $actors[] = ['key' => (string) $id, 'kind' => 'deleted_user', 'name' => 'Deleted user'];
        }
        if (isset($kinds['cli'])) {
            $actors[] = ['key' => 'cli', 'kind' => 'cli', 'name' => 'Command line'];
        }
        if (isset($kinds['system'])) {
            $actors[] = ['key' => 'system', 'kind' => 'system', 'name' => 'System'];
        }

        return $actors;
    }

    /** @return Builder<AuditEntry> */
    private function visible(User $u): Builder
    {
        $query = AuditEntry::query();
        $this->visibility->constrain($query, $u);

        return $query;
    }

    /** An instant as `occurred_at` stores it: in the app's time zone, to the microsecond. */
    private function stored(DateTimeInterface $at): string
    {
        return Carbon::instance($at)->setTimezone(new DateTimeZone(config()->string('app.timezone')))->format('Y-m-d H:i:s.u');
    }
}
