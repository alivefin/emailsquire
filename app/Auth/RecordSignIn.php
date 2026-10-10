<?php

namespace App\Auth;

use App\Http\Middleware\RequireStepUp;
use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Starts the 12 h absolute-session clock, drops any step-up confirmation and any pending two-factor
 * sign-in the session held from before this sign-in (they belonged to whoever was there then), and
 * stamps the user's last sign-in.
 */
class RecordSignIn
{
    public function handle(Login $event): void
    {
        if (request()->hasSession()) {
            request()->session()->put('auth.signed_in_at', now()->getTimestamp());
            request()->session()->forget([RequireStepUp::SESSION_KEY, ...RedirectIfTwoFactorAuthenticatable::PENDING_KEYS]);
        }

        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->save();
        }
    }
}
