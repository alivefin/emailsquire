<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendWelcomeRequest;
use App\Mail\MailboxWelcomes;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use Illuminate\Http\Response;

class MailboxWelcomeController extends Controller
{
    /**
     * Emails the mailbox's setup instructions to `to`, in `locale`: the address (also the username),
     * IMAP on `emailsquire.mail_host` port 993 with SSL/TLS, SMTP on port 587 with STARTTLS, and the
     * webmail link when one is configured. Never the password. The mail is queued; the answer is 202
     * with no body. Audited as `mailbox.welcome_sent` with `changes: {to}`.
     *
     * @status 202
     */
    public function __invoke(SendWelcomeRequest $request, Domain $domain, Mailbox $mailbox, MailboxWelcomes $welcomes): Response
    {
        $welcomes->send($mailbox->email, $domain->name, $request->recipient(), $request->mailLocale());

        return response()->noContent(202);
    }
}
