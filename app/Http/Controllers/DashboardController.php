<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditEntryResource;
use App\Http\Resources\MailboxResource;
use App\MailServer\Queries\Dashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    /**
     * @response array{
     *     counts: array{domains: int, domains_added_this_month: int, mailboxes: int, forwarding: int, forwarding_local: int, forwarding_external: int},
     *     near_full: array{count: int, items: list<array{local: string, address: string, display_name: string|null, quota_bytes: int, quota_is_default: bool, status: 'active'|'disabled', used_bytes: int|null, usage_checked_at: string|null, forwarding_count: int, domain: string}>},
     *     recent_activity: list<array{occurred_at: string, actor_kind: 'you'|'user'|'deleted_user'|'cli'|'system', actor_name: string, action: string, object_type: string, object_key: string, domain_name: string|null, changes: array<string, mixed>|null}>,
     *     server_storage: array{total_bytes: int, free_bytes: int, used_bytes: int, checked_at: string}|null,
     *     usage_checked_at: string|null,
     * }
     */
    public function __invoke(Request $request, Dashboard $dashboard): JsonResponse
    {
        $nearFull = $dashboard->nearFull(10);

        return response()->json([
            'counts' => $dashboard->counts(),
            'near_full' => [
                'count' => $nearFull['count'],
                'items' => array_map(
                    fn (array $row): array => (new MailboxResource($row))->resolve($request) + ['domain' => $row['domain']],
                    $nearFull['items'],
                ),
            ],
            'recent_activity' => AuditEntryResource::collection($dashboard->recentActivity(5))->resolve($request),
            'server_storage' => $dashboard->serverStorage(),
            'usage_checked_at' => $dashboard->usageCheckedAt(),
        ]);
    }
}
