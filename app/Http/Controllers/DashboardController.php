<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditEntryResource;
use App\Http\Resources\MailboxResource;
use App\MailServer\Queries\Dashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
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
