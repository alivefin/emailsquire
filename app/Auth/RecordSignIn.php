<?php

namespace App\Auth;

use App\Http\Middleware\RequireStepUp;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Starts the absolute-session clock (AbsoluteSessionLimit: 12 h console, 2 h portal), drops any step-up
 * confirmation, any pending two-factor sign-in and any passkey ceremony's options the session held from
 * before this sign-in (they belonged to whoever was there then), and stamps a console user's last sign-in. Runs for both guards.
 */
class RecordSignIn
{
    public function handle(Login $event): void
    {
        if (request()->hasSession()) {
            request()->session()->put('auth.signed_in_at', now()->getTimestamp());
            request()->session()->forget([RequireStepUp::SESSION_KEY, ...RedirectIfTwoFactorAuthenticatable::PENDING_KEYS, ...Passkeys::SESSION_KEYS]);
        }

        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->save();
        }
    }
}
