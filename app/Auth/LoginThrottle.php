<?php

namespace App\Auth;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use LogicException;

/**
 * The `login` limiter on POST /auth/login (`throttle:login`, AppServiceProvider): five sign-in attempts
 * a minute per email and IP address, counted for every attempt. A finished console sign-in clears its
 * bucket, so an admin who mistyped a few times and then got in is not locked out of the next sign-in.
 *
 * Fortify's LoginRateLimiter::clear() (in its PrepareAuthenticatedSession) can't do that here: it
 * clears the bare key, while ThrottleRequests keeps a named limiter's count under md5(name.key).
 *
 * The `mailbox-login` limiter counts the attempts that reach the mailbox check (the console account did
 * not sign in), five a minute per email and IP, on top of `login`. Each kind of sign-in clears only its
 * own bucket: a console sign-in leaves the mailbox count alone and a mailbox sign-in leaves `login`
 * alone, so knowing one password never buys more guesses at the other.
 */
final class LoginThrottle
{
    public const LIMITER = 'login';

    public const MAILBOX_LIMITER = 'mailbox-login';

    /** The limiter key for $email (as typed; any non-string counts as '') from $ip. */
    public static function key(mixed $email, ?string $ip): string
    {
        return Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.$ip);
    }

    /** Clears the `throttle:login` count of $email from the request's IP address. */
    public static function clear(string $email, Request $request): void
    {
        // ThrottleRequests' key for a named limiter (it hashes keys by default).
        RateLimiter::clear(md5(self::LIMITER.self::key($email, $request->ip())));
    }

    /** The `mailbox-login` key of $email (as typed; any non-string counts as '') from $ip. */
    public static function mailboxKey(mixed $email, ?string $ip): string
    {
        return 'mailbox|'.self::key($email, $ip);
    }

    /**
     * Counts one mailbox check for the request's email and IP, or answers 429 (`too_many_requests`,
     * with Retry-After) when this minute's are used up. Runs before Dovecot is asked.
     *
     * @throws ThrottleRequestsException
     */
    public static function hitMailbox(Request $request): void
    {
        $limit = RateLimiter::limiter(self::MAILBOX_LIMITER)?->__invoke($request);
        if (! $limit instanceof Limit || ! is_string($limit->key)) {
            throw new LogicException('The mailbox-login limiter must answer one Limit keyed by mailboxKey().');
        }

        $key = md5(self::MAILBOX_LIMITER.$limit->key);
        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, $limit->decaySeconds);
    }

    /** Clears the `mailbox-login` count of $email from the request's IP address. */
    public static function clearMailbox(string $email, Request $request): void
    {
        RateLimiter::clear(md5(self::MAILBOX_LIMITER.self::mailboxKey($email, $request->ip())));
    }
}
