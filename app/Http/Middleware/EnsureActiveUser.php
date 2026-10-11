<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A session whose account has gone since it signed in ends here: the guard signs out, the session is
 * invalidated (a reactivated account does not get it back) and the request answers the usual 401
 * `unauthenticated` problem.
 *
 * - Console (`web`): the guard reloads the user on every request; a deactivated one ends here.
 * - Portal (`mailbox`): MailboxUserProvider returns no principal for a deleted or disabled mailbox,
 *   so `auth:mailbox` refuses before any route middleware runs; Authenticate::unauthenticated()
 *   ends that session with end().
 */
class EnsureActiveUser
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->isActive()) {
            self::end($request, Auth::getDefaultDriver());
        }

        return $next($request);
    }

    /**
     * Signs $guard out, invalidates the session and answers 401 `unauthenticated`.
     *
     * @throws AuthenticationException
     */
    public static function end(Request $request, string $guard): never
    {
        Auth::guard($guard)->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        throw new AuthenticationException('Unauthenticated.', [$guard]);
    }
}
