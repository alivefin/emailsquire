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

        return [
            'occurred_at' => Carbon::parse($e->occurred_at)->toIso8601String(),
            'actor_name' => $this->actorName($request, $e),
            'action' => $e->action,
            'object_type' => $e->object_type,
            'object_key' => $e->object_key,
            'domain_name' => $e->domain_name,
            'changes' => $e->changes,
        ];
    }

    private function actorName(Request $request, AuditEntry $e): string
    {
        if ($e->actor_type === 'cli') {
            return 'Command line';
        }
        if ($e->actor_type !== 'user') {
            return 'System';
        }

        $id = is_numeric($e->actor_id) ? (int) $e->actor_id : null;
        if ($id !== null && $request->user()?->getAuthIdentifier() === $id) {
            return 'You';
        }

        $user = $id === null ? null : User::query()->find($id);

        return $user instanceof User ? $user->name : 'Deleted user';
    }
}
