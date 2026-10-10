<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Ends a session 12 hours after sign-in, however active it has been. */
class AbsoluteSessionLimit
{
    public const SESSION_KEY = 'auth.signed_in_at';

    public const MAX_AGE_SECONDS = 12 * 3600;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $signedInAt = $request->session()->get(self::SESSION_KEY);

        if (! is_int($signedInAt)) {
            $request->session()->put(self::SESSION_KEY, now()->getTimestamp());
        } elseif (now()->getTimestamp() - $signedInAt > self::MAX_AGE_SECONDS) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return Problem::response(401, 'Unauthorized', 'Your session has expired. Sign in again.', 'session_expired');
        }

        return $next($request);
    }
}
