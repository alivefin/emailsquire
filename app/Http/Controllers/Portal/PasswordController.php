<?php

namespace App\Http\Controllers\Portal;

use App\Auth\Mailbox\MailboxPrincipal;
use App\Auth\Mailbox\MailboxUserProvider;
use App\Auth\OtherSessions;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Http\Requests\Portal\ChangePasswordRequest;
use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\Repositories\MailboxRepository;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** The mailbox owner changes their own mail password. */
#[Group('Portal')]
class PasswordController extends Controller
{
    /**
     * Needs the current password, which the mail server checks, and follows the mailbox rules. The
     * new password works for IMAP and SMTP straight away. Ends the mailbox's other mail sessions and
     * other portal sessions, and keeps this one signed in on a new session id. Every attempt counts
     * against 6 a minute per mailbox, wrong current passwords included.
     */
    public function update(ChangePasswordRequest $request, PasswordChecker $checker, MailboxRepository $mailboxes, MailboxUserProvider $provider): Response|JsonResponse
    {
        $mailbox = $request->user('mailbox');
        if (! $mailbox instanceof MailboxPrincipal) {
            throw new AuthenticationException('Unauthenticated.', ['mailbox']);
        }

        try {
            $current = $checker->verify($mailbox->address, $request->currentPassword(), $request->ip());
        } catch (DovecotAuthException $e) {
            // The reason only, as on sign-in; never the address or the password.
            Log::error('Portal password change could not reach the Dovecot auth socket', ['reason' => $e->reason->name]);

            return Problem::response(503, 'Service Unavailable', __('emailsquire.problem.password_check_unavailable'), 'password_check_unavailable');
        } catch (InvalidArgumentException) {
            $current = false; // the client's IP is not an IP address: nothing to check against
        }

        if (! $current) {
            // The same fail2ban line as a refused mailbox sign-in (AuthFailureLogger); never the password.
            event(new Failed('mailbox', $mailbox, ['email' => $mailbox->address]));

            throw ValidationException::withMessages(['current_password' => [trans()->string('validation.current_password')]]);
        }

        // Gone, disabled, or given another password since this request was authenticated (an admin's
        // reset, Roundcube, another tab): nothing changed, and the session is over. The principal's
        // hash is the one AuthenticateSession checked this session against.
        if (! $mailboxes->changeOwnPassword($mailbox->address, $mailbox->getAuthPassword(), $request->newPassword())) {
            throw new AuthenticationException('Unauthenticated.', ['mailbox']);
        }

        $this->keepThisSession($request, $mailbox->address, $provider);

        return response()->noContent();
    }

    /**
     * The other portal sessions of the mailbox end now; their stored password hash no longer matches
     * either, so one written meanwhile ends at its next request (AuthenticateSession). This one moves
     * to a new id and stores the new hash, so it stays signed in.
     */
    private function keepThisSession(ChangePasswordRequest $request, string $address, MailboxUserProvider $provider): void
    {
        $session = $request->session();
        $session->regenerate(true);
        OtherSessions::endMailbox($address, $session->getId());

        $guard = Auth::guard('mailbox');
        $fresh = $provider->retrieveById($address);
        if ($fresh === null) {
            return; // the next request finds no mailbox and ends the session
        }
        // The guard still holds the principal it loaded with the old hash; AuthenticateSession writes
        // the hash of whatever the guard holds into the session after this request.
        $guard->setUser($fresh);
        $session->put('password_hash_mailbox', $guard->hashPasswordForCookie($fresh->getAuthPassword()));
    }
}
