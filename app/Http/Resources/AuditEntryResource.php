<?php

namespace App\Http\Resources;

use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/** @property AuditEntry $resource */
class AuditEntryResource extends JsonResource
{
    public static $wrap = null;

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
             * Who acted, as a stable value the console words in its own language: `you` is the requester.
             *
             * @var 'you'|'user'|'deleted_user'|'cli'|'system'
             */
            'actor_kind' => $kind,
            /** @var string The user's name; an English label for the other kinds. */
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

    /** @return array{0: 'you'|'user'|'deleted_user'|'cli'|'system', 1: string} the kind and the name */
    private function actor(Request $request, AuditEntry $e): array
    {
        if ($e->actor_type === 'cli') {
            return ['cli', 'Command line'];
        }
        if ($e->actor_type !== 'user') {
            return ['system', 'System'];
        }

        $id = is_numeric($e->actor_id) ? (int) $e->actor_id : null;
        if ($id !== null && $request->user()?->getAuthIdentifier() === $id) {
            return ['you', 'You'];
        }

        $user = $id === null ? null : User::query()->find($id);

        return $user instanceof User ? ['user', $user->name] : ['deleted_user', 'Deleted user'];
    }
}
