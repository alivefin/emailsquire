<?php

namespace App\Auth;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
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
 * not sign in), on top of `login`: five a minute per address and IP, and thirty a minute per IP across
 * all addresses, so cycling through addresses on a hosted domain is throttled too. Each kind of sign-in clears only its
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

    /**
     * The per-address `mailbox-login` key of $email from $ip. An address that parses is keyed by its
     * normalised form (lowercase, the domain in punycode), so `eero@exämple.fi` and
     * `eero@xn--exmple-cua.fi` share a bucket; anything else is keyed as typed (any non-string as '').
     */
    public static function mailboxKey(mixed $email, ?string $ip): string
    {
        if (is_string($email)) {
            try {
                $email = app(AddressNormalizer::class)->address($email)->address();
            } catch (InvalidAddress) {
                // keyed as typed
            }
        }

        return 'mailbox|'.self::key($email, $ip);
    }

    /** The per-IP `mailbox-login` key: every address one IP tries shares it. */
    public static function mailboxIpKey(?string $ip): string
    {
        return 'mailbox-ip|'.$ip;
    }

    /**
     * Counts one mailbox check for the request's email and IP, or answers 429 (`too_many_requests`,
     * with Retry-After) when either `mailbox-login` bucket is used up this minute: the address's own,
     * or the IP's across all addresses. Runs before Dovecot is asked; a refused attempt counts in neither.
     *
     * @throws ThrottleRequestsException
     */
    public static function hitMailbox(Request $request): void
    {
        $limits = RateLimiter::limiter(self::MAILBOX_LIMITER)?->__invoke($request);
        $limits = is_array($limits) ? $limits : [$limits];

        $buckets = [];
        foreach ($limits as $limit) {
            if (! $limit instanceof Limit || ! is_string($limit->key)) {
                throw new LogicException('The mailbox-login limiter must answer Limits keyed by mailboxKey() and mailboxIpKey().');
            }
            $key = md5(self::MAILBOX_LIMITER.$limit->key);
            if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, ['Retry-After' => RateLimiter::availableIn($key)]);
            }
            $buckets[$key] = $limit->decaySeconds;
        }

        foreach ($buckets as $key => $decaySeconds) {
            RateLimiter::hit($key, $decaySeconds);
        }
    }

    /**
     * Clears the per-address `mailbox-login` count of $email from the request's IP address. The IP's
     * bucket is left to expire: one good password must not reset the count of other addresses tried.
     */
    public static function clearMailbox(string $email, Request $request): void
    {
        RateLimiter::clear(md5(self::MAILBOX_LIMITER.self::mailboxKey($email, $request->ip())));
    }
}
