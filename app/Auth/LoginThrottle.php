<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The `login` limiter on POST /auth/login (`throttle:login`, AppServiceProvider): five sign-in attempts
 * a minute per email and IP address, counted for every attempt. A finished sign-in clears its bucket,
 * so an admin who mistyped a few times and then got in is not locked out of the next sign-in.
 *
 * Fortify's LoginRateLimiter::clear() (in its PrepareAuthenticatedSession) can't do that here: it
 * clears the bare key, while ThrottleRequests keeps a named limiter's count under md5(name.key).
 */
final class LoginThrottle
{
    public const LIMITER = 'login';

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
}
