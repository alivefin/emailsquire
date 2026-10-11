<?php

namespace App\Auth;

use App\Auth\Mailbox\MailboxPrincipal;
use App\Auth\Mailbox\MailboxUserProvider;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\Models\Domain;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\LoginRateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The step of the POST /auth/login pipeline that picks the guard (spec decision 1). It runs before
 * Fortify's two-factor and sign-in steps (AppServiceProvider::bootConsoleSignIn()).
 *
 * 1. The console account is checked first (ConsoleCredentials). If it signs in, the rest of the
 *    pipeline runs exactly as before: two-factor, `web` sign-in, the `login` throttle cleared. With
 *    `mailbox: true` in the body this step is skipped, so someone whose console and mail passwords are
 *    the same can still reach their portal; the request never reaches the console steps after this one.
 * 2. Otherwise the address is tried as a mailbox: whenever it is on a domain this server hosts,
 *    mailbox or not, it counts against `mailbox-login` and Dovecot is asked. Only an existing,
 *    enabled mailbox whose password Dovecot accepts signs in, on the `mailbox` guard: `{"kind": "mailbox"}`.
 * 3. Anything else is the one refusal Fortify gives: 422 with `errors.email` "Email or password is
 *    wrong.", whatever exists. The fail2ban line says `kind=mailbox` when the address is a mailbox
 *    (enabled or not) and `kind=console` otherwise.
 *
 * Timing: Dovecot answers every failure only after auth_failure_delay (2 s). Asking it for every
 * address on a hosted domain, including ones with no mailbox, keeps "is this a mailbox" out of the
 * response time; an address on a domain not hosted here is answered without it, which tells nothing
 * that DNS does not (MX records). The console check costs one password hash on every path (DummyHash).
 */
final class SignInRouting
{
    public function __construct(
        private readonly MailboxUserProvider $mailboxes,
        private readonly PasswordChecker $checker,
        private readonly AddressNormalizer $normalizer,
        private readonly LoginRateLimiter $limiter,
    ) {}

    /** @param  Closure(Request): mixed  $next */
    public function handle(Request $request, Closure $next): mixed
    {
        // `mailbox: true` (the sign-in card's "manage my mailbox", validated by LoginRequest) skips the
        // console account: the sign-in is a mailbox one or the usual refusal, never a console session.
        if (! $request->boolean('mailbox') && ConsoleCredentials::check($request) !== null) {
            return $next($request);
        }

        $email = $request->string(Fortify::username())->toString();
        [$principal, $isMailbox] = $this->mailbox($email, $request->string('password')->toString(), $request);

        if ($principal === null) {
            $this->refuse($request, $isMailbox ? 'mailbox' : 'web');
        }

        LoginThrottle::clearMailbox($email, $request);
        // Regenerates the session id; the Login event drops any console sign-in (SingleGuardSession).
        Auth::guard('mailbox')->login($principal);

        return new JsonResponse(['kind' => 'mailbox'], Response::HTTP_OK);
    }

    /**
     * The enabled mailbox $email signs in to with $password, and whether $email is a mailbox at all.
     *
     * @return array{0: MailboxPrincipal|null, 1: bool}
     */
    private function mailbox(string $email, #[\SensitiveParameter] string $password, Request $request): array
    {
        try {
            $address = $this->normalizer->address($email);
        } catch (InvalidAddress) {
            return [null, false]; // no mailbox can have this name
        }

        try {
            if (! $this->hosted($address)) {
                return [null, false];
            }
            // Only an address this server hosts is a mailbox check (Dovecot is asked), so only it counts
            // against `mailbox-login`: console typos on other domains never use up an IP's thirty.
            LoginThrottle::hitMailbox($request);
            $principal = $this->mailboxes->retrieveByAddress($address);
            $isMailbox = $principal !== null || $this->mailboxes->exists($address);
        } catch (QueryException $e) {
            // The guide is unreadable: no mailbox can sign in, and console sign-in is not affected.
            report($e);

            return [null, false];
        }

        // An unknown or disabled address is asked too (a disabled one carries a throwaway hash), so
        // its refusal takes Dovecot's failure delay like a wrong password.
        try {
            $accepted = $this->checker->verify($principal->address ?? $address->address(), $password, $request->ip());
        } catch (DovecotAuthException $e) {
            // Fails closed with the usual refusal. Never logs the address: the fail2ban line does that.
            Log::error('Mailbox sign-in could not reach the Dovecot auth socket', ['reason' => $e->reason->name]);
            $accepted = false;
        } catch (InvalidArgumentException) {
            $accepted = false;
        }

        return [$accepted ? $principal : null, $isMailbox];
    }

    private function hosted(EmailAddress $address): bool
    {
        return Domain::query()->where('name', $address->domain->ascii)->exists();
    }

    /**
     * Fortify's own refusal (AttemptToAuthenticate), with the guard that was tried last for the log.
     *
     * @throws ValidationException
     */
    private function refuse(Request $request, string $guard): never
    {
        event(new Failed($guard, null, [Fortify::username() => $request->string(Fortify::username())->toString()]));
        $this->limiter->increment($request);

        throw ValidationException::withMessages([Fortify::username() => [trans('auth.failed')]]);
    }
}
