<?php

namespace App\Http\Controllers;

use App\Http\Resources\AuditEntryResource;
use App\MailServer\AuditActions;
use App\MailServer\Queries\AuditLog;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/** The activity log: the audit rows the signed-in user may read (AuditVisibility). */
class AuditLogController extends Controller
{
    /**
     * What happened on the user's domains, newest first (ties broken by id); a server admin sees every row.
     *
     * Anyone else sees the rows of the domains they have a role on, without account and server
     * history (`admin.*`, `maildir.*`, `user.*` except `user.invited`). `domain` is an ASCII domain
     * name; one that is unknown or outside the user's domains gives an empty page. `actor` is a user
     * id, `cli` or `system` (GET /audit-log/actors lists them). `from` (inclusive) and `to` (exclusive)
     * are ISO 8601 date-times with an offset.
     *
     * @response array{data: list<\App\Http\Resources\AuditEntryResource>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request, AuditLog $log): JsonResponse
    {
        $request->validate([
            'domain' => ['nullable', 'string', 'max:255'],
            'actor' => ['nullable', 'string', 'regex:/^(cli|system|[1-9][0-9]{0,18})$/'],
            'action_group' => ['nullable', 'string', Rule::in(array_keys(AuditActions::GROUPS))],
            /** Inclusive: an ISO 8601 date-time with an offset (`Z` or `±hh:mm`); seconds and fractions optional. */
            'from' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', 'date'],
            /** Exclusive: an ISO 8601 date-time with an offset, as `from`. */
            'to' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/', 'date'],
        ]);

        $page = max(1, $request->integer('page', 1));
        /** How many rows a page holds: 10 by default, at most 200. `limit` is accepted too, as on the other lists. */
        $perPage = $request->integer('per_page', $request->integer('limit', 10));
        $result = $log->paginate($this->user($request), [
            'domain' => $this->text($request, 'domain'),
            'actor' => $this->text($request, 'actor'),
            'action_group' => $this->text($request, 'action_group'),
            'from' => $this->instant($request, 'from'),
            'to' => $this->instant($request, 'to'),
        ], $page, min(200, max(1, $perPage)));

        return response()->json([
            'data' => AuditEntryResource::collection($result->items())->resolve($request),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    /**
     * Who acted in the rows the user may read, for the activity log's actor filter.
     *
     * People by name, then deleted users, the command line and the system. `key` is the value for
     * `actor`. `name` is the person's name for `user` and an English label for the other kinds, which
     * the console words itself.
     *
     * @response array{data: list<array{key: string, kind: 'user'|'deleted_user'|'cli'|'system', name: string}>}
     */
    public function actors(Request $request, AuditLog $log): JsonResponse
    {
        return response()->json(['data' => $log->actors($this->user($request))]);
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(401);
    }

    private function text(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function instant(Request $request, string $key): ?Carbon
    {
        $value = $this->text($request, $key);

        return $value === null ? null : Carbon::parse($value);
    }
}
