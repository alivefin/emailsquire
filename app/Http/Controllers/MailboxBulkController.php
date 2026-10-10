<?php

namespace App\Http\Controllers;

use App\Http\Requests\BulkMailboxRequest;
use App\MailServer\Models\Domain;
use App\MailServer\Repositories\MailboxRepository;
use Illuminate\Http\JsonResponse;

class MailboxBulkController extends Controller
{
    public function __invoke(BulkMailboxRequest $request, Domain $domain, MailboxRepository $mailboxes): JsonResponse
    {
        $changed = $mailboxes->bulk($domain, $request->locals(), $request->action(), $request->quotaBytes());

        return response()->json(['changed' => $changed]);
    }
}
