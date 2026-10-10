<?php

namespace App\Http\Controllers;

use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\Repositories\MailboxRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class MailboxStateController extends Controller
{
    public function disable(Domain $domain, Mailbox $mailbox, MailboxRepository $mailboxes): Response
    {
        $mailboxes->disable($mailbox);

        return response()->noContent();
    }

    public function enable(Domain $domain, Mailbox $mailbox, MailboxRepository $mailboxes): Response
    {
        $mailboxes->enable($mailbox);

        return response()->noContent();
    }

    /**
     * `forwarding_removed` counts the sources that forwarded into the mailbox on domains the user has a role on.
     *
     * @response array{forwarding_removed: int}
     */
    public function destroy(Request $request, Domain $domain, Mailbox $mailbox, MailboxRepository $mailboxes): JsonResponse
    {
        return response()->json($mailboxes->delete($mailbox, $this->domainScope($request)));
    }
}
