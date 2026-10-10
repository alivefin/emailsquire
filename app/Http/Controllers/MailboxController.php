<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMailboxRequest;
use App\Http\Requests\UpdateMailboxRequest;
use App\Http\Resources\MailboxResource;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\Queries\MailboxList;
use App\MailServer\Repositories\MailboxRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxController extends Controller
{
    /**
     * @response array{data: list<\App\Http\Resources\MailboxResource>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request, Domain $domain, MailboxList $list): JsonResponse
    {
        $q = $request->query('q');
        $status = $request->query('status');
        $sort = $request->query('sort');
        $result = $list->paginate(
            $this->domainScope($request),
            $domain,
            is_string($q) ? $q : null,
            is_string($status) ? $status : 'all',
            $sort === 'desc' ? 'desc' : 'asc',
            max(1, $request->integer('page', 1)),
            min(200, max(1, $request->integer('limit', 10))),
        );

        return response()->json([
            'data' => MailboxResource::collection($result->items())->resolve($request),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    public function store(StoreMailboxRequest $request, Domain $domain, MailboxRepository $mailboxes, MailboxList $list): JsonResponse
    {
        $address = $request->address();
        $mailboxes->create($domain, $address, $request->string('password')->value(), $request->integer('quota_bytes'), $request->displayName());

        return $this->resource($request, $list, $domain, $address->address())->response()->setStatusCode(201);
    }

    public function show(Request $request, Domain $domain, Mailbox $mailbox, MailboxList $list): MailboxResource
    {
        return $this->resource($request, $list, $domain, $mailbox->email);
    }

    public function update(UpdateMailboxRequest $request, Domain $domain, Mailbox $mailbox, MailboxRepository $mailboxes, MailboxList $list): MailboxResource
    {
        $mailboxes->update($mailbox, $request->quotaBytes(), $request->hasDisplayName(), $request->displayName());

        return $this->resource($request, $list, $domain, $mailbox->email);
    }

    private function resource(Request $request, MailboxList $list, Domain $domain, string $address): MailboxResource
    {
        $row = $list->find($this->domainScope($request), $domain, $address) ?? abort(404);

        return new MailboxResource($row);
    }
}
