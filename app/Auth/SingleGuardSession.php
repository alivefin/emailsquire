<?php

namespace App\Auth;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A session is a console session (`web`) or a portal session (`mailbox`), never both (spec decision
 * 2). A sign-in on one guard signs the other out of this session; the sign-in itself has already
 * regenerated the session id (SessionGuard::login()), and RecordSignIn drops any step-up window and
 * pending two-factor sign-in. A console sign-in that stops at the two-factor step ends the portal
 * session too (RedirectIfTwoFactorAuthenticatable::startPendingSignIn()).
 */
class SingleGuardSession
{
    public const GUARDS = ['web', 'mailbox'];

    public function handle(Login $event): void
    {
        if (request()->hasSession()) {
            self::endOthers(request(), $event->guard);
        }
    }

    /** Signs every guard but $guard out of the request's session. */
    public static function endOthers(Request $request, string $guard): void
    {
        $session = $request->session();

        foreach (self::GUARDS as $name) {
            if ($name === $guard) {
                continue;
            }
            $other = Auth::guard($name);
            if ($other instanceof SessionGuard && $session->has($other->getName())) {
                try {
                    $other->logout();
                } catch (QueryException) {
                    // logout() loads the user first; with the guide unreadable that fails, and the
                    // sign-in must not. Removing the key signs the guard out of this session all the same.
                    $session->forget($other->getName());
                }
            }
            // SessionGuard::login() and AuthenticateSession keep this beside the user id.
            $session->forget('password_hash_'.$name);
        }
    }
}
