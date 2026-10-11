<?php

namespace App\Auth;

use Illuminate\Auth\Events\Login;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;

/**
 * A session is a console session (`web`) or a portal session (`mailbox`), never both (spec decision
 * 2). A sign-in on one guard signs the other out of this session; the sign-in itself has already
 * regenerated the session id (SessionGuard::login()), and RecordSignIn drops any step-up window and
 * pending two-factor sign-in.
 */
class SingleGuardSession
{
    public const GUARDS = ['web', 'mailbox'];

    public function handle(Login $event): void
    {
        if (! request()->hasSession()) {
            return;
        }
        $session = request()->session();

        foreach (self::GUARDS as $name) {
            if ($name === $event->guard) {
                continue;
            }
            $guard = Auth::guard($name);
            if ($guard instanceof SessionGuard && $session->has($guard->getName())) {
                $guard->logout();
            }
            // SessionGuard::login() and AuthenticateSession keep this beside the user id.
            $session->forget('password_hash_'.$name);
        }
    }
}
