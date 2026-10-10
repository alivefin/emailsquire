<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/** Starts the 12 h absolute-session clock and stamps the user's last sign-in. */
class RecordSignIn
{
    public function handle(Login $event): void
    {
        if (request()->hasSession()) {
            request()->session()->put('auth.signed_in_at', now()->getTimestamp());
        }

        if ($event->user instanceof User) {
            $event->user->forceFill(['last_login_at' => now()])->save();
        }
    }
}
