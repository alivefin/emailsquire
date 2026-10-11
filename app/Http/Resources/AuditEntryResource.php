<?php

namespace App\Http\Resources;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * One audit row for the activity log and the dashboard. Resolve a page with list(), which reads the
 * actors' names in one query.
 *
 * @property AuditEntry $resource
 */
class AuditEntryResource extends JsonResource
{
    public static $wrap = null;

    /** @var array<int, string>|null user id => name for the rows being resolved; null reads one row's actor alone */
    private ?array $names = null;

    /**
     * The rows as the API returns them, with every actor's name read in a single query.
     *
     * @param  list<AuditEntry>  $entries
     * @return list<array<mixed>> each as JsonResource::resolve() gives it
     */
    public static function list(array $entries, Request $request): array
    {
        $names = self::names($entries);

        return array_map(function (AuditEntry $e) use ($names, $request): array {
            $resource = new self($e);
            $resource->names = $names;

            return $resource->resolve($request);
        }, $entries);
    }

    /**
     * @param  list<AuditEntry>  $entries
     * @return array<int, string> user id => name, for the user actors that still exist
     */
    private static function names(array $entries): array
    {
        $ids = [];
        foreach ($entries as $e) {
            if ($e->actor_type === 'user' && is_numeric($e->actor_id)) {
                $ids[(int) $e->actor_id] = true;
            }
        }

        $names = [];
        if ($ids !== []) {
            foreach (User::query()->whereIn('id', array_keys($ids))->get(['id', 'name']) as $user) {
                $names[$user->id] = $user->name;
            }
        }

        return $names;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $e = $this->resource;
        [$kind, $name] = $this->actor($request, $e);

        return [
            /** @var int */
            'id' => $e->id,
            /** ISO 8601 */
            'occurred_at' => Carbon::parse($e->occurred_at)->toIso8601String(),
            /**
             * Who acted, as a stable value the console words in its own language: `you` is the requester,
             * `mailbox` a mailbox owner in the portal.
             *
             * @var 'you'|'user'|'deleted_user'|'cli'|'system'|'mailbox'
             */
            'actor_kind' => $kind,
            /** @var string The user's name, or the mailbox's address; an English label for the other kinds. */
            'actor_name' => $name,
            /** @var string */
            'action' => $e->action,
            /** @var string */
            'object_type' => $e->object_type,
            /** @var string */
            'object_key' => $e->object_key,
            /** @var string|null */
            'domain_name' => $e->domain_name,
            /** @var array<string, mixed>|null */
            'changes' => $e->changes,
        ];
    }

    /** @return array{0: 'you'|'user'|'deleted_user'|'cli'|'system'|'mailbox', 1: string} the kind and the name */
    private function actor(Request $request, AuditEntry $e): array
    {
        if ($e->actor_type === 'cli') {
            return ['cli', 'Command line'];
        }
        // A mailbox owner, by the address they acted as; a console user never reads it as "you".
        if ($e->actor_type === 'mailbox' && is_string($e->actor_id) && $e->actor_id !== '') {
            return ['mailbox', $e->actor_id];
        }
        if ($e->actor_type !== 'user') {
            return ['system', 'System'];
        }

        $id = is_numeric($e->actor_id) ? (int) $e->actor_id : null;
        if ($id !== null && $request->user()?->getAuthIdentifier() === $id) {
            return ['you', 'You'];
        }

        $names = $this->names ?? self::names([$e]);
        $name = $id === null ? null : ($names[$id] ?? null);

        return $name !== null ? ['user', $name] : ['deleted_user', 'Deleted user'];
    }
}
