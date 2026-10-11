<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends a session a fixed time after sign-in, however active it has been: 12 hours for the console
 * (`web`), 2 hours for the portal (`mailbox`). Runs after `auth:web` or `auth:mailbox`, so the default
 * guard is the one this session signed in on.
 */
class AbsoluteSessionLimit
{
    public const SESSION_KEY = 'auth.signed_in_at';

    /** The console's limit, kept for the callers that predate the portal. */
    public const MAX_AGE_SECONDS = self::MAX_AGE_BY_GUARD['web'];

    /** Seconds per guard; a guard not listed gets the shortest. */
    public const MAX_AGE_BY_GUARD = ['web' => 12 * 3600, 'mailbox' => 2 * 3600];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $signedInAt = $request->session()->get(self::SESSION_KEY);

        if (! is_int($signedInAt)) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        } elseif (now()->getTimestamp() - $signedInAt > self::maxAge(Auth::getDefaultDriver())) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Problem::response(401, 'Unauthorized', __('emailsquire.problem.session_expired'), 'session_expired');
        }

        return $next($request);
    }

    public static function maxAge(string $guard): int
    {
        return self::MAX_AGE_BY_GUARD[$guard] ?? min(self::MAX_AGE_BY_GUARD);
    }
}
