<?php

namespace App\Http\Controllers;

use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\Repositories\MailboxRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MailboxPasswordController extends Controller
{
    /** The only response that ever carries a mailbox password; it must not be cached. */
    public function __invoke(Request $request, Domain $domain, Mailbox $mailbox, MailboxRepository $mailboxes): JsonResponse
    {
        $request->validate(['generate' => ['required', 'accepted']]);

        return response()->json(['password' => $mailboxes->resetPassword($mailbox)])
            ->header('Cache-Control', 'no-store');
    }
}
