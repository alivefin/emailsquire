<?php

namespace App\Auth;

use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;

/**
 * Writes the one line fail2ban's `emailsquire` filter matches (deploy/fail2ban/filter.d). Never logs the password.
 */
class AuthFailureLogger
{
    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? '';
        $email = is_string($email) ? $email : '';
        // The address is attacker-controlled: keep it to printable, space-free ASCII on one line.
        $email = substr((string) preg_replace('/[^\x21-\x7e]/', '?', $email), 0, 100);

        Log::channel('stack')->warning(sprintf('emailsquire auth failure user=%s ip=%s', $email, request()->ip()));
    }
}
