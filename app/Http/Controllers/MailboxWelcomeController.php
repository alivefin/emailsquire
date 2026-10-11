<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendWelcomeRequest;
use App\Mail\MailboxWelcomes;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;

class MailboxWelcomeController extends Controller
{
    /** Welcome emails one user may send an hour. Each goes to an address the user typed. */
    public const PER_HOUR = 10;

    /**
     * Emails the mailbox's setup instructions to `to`, in `locale`: the address (also the username),
     * IMAP on `emailsquire.mail_host` port 993 with SSL/TLS, SMTP on port 587 with STARTTLS, and the
     * webmail link when one is configured. Never the password. The mail is queued; the answer is 202
     * with no body. Audited as `mailbox.welcome_sent` with `changes: {to}`.
     *
     * Only mails that were queued count against the 10 an hour: a request refused by validation or
     * authorization costs nothing.
     *
     * @status 202
     */
    public function __invoke(SendWelcomeRequest $request, Domain $domain, Mailbox $mailbox, MailboxWelcomes $welcomes): Response
    {
        $user = $request->user();
        $key = 'welcome:user:'.($user instanceof User ? $user->id : $request->ip());
        if (RateLimiter::tooManyAttempts($key, self::PER_HOUR)) {
            throw new ThrottleRequestsException('Too Many Attempts.', headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }

        $welcomes->send($mailbox->email, $domain->name, $request->recipient(), $request->mailLocale());
        RateLimiter::hit($key, 3600);

        return response()->noContent(202);
    }
}
