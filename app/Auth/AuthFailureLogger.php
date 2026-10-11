<?php

namespace App\Auth;

use Illuminate\Auth\Events\Failed;
use Illuminate\Support\Facades\Log;

/**
 * Writes the one line fail2ban's `emailsquire` filter matches (deploy/fail2ban/filter.d). Never logs the password.
 * `kind` is `mailbox` for a refused mailbox sign-in (the `mailbox` guard) and `console` for the rest.
 */
class AuthFailureLogger
{
    public function handle(Failed $event): void
    {
        $email = $event->credentials['email'] ?? '';
        $email = is_string($email) ? $email : '';
        // The address is attacker-controlled: keep it to printable, space-free ASCII on one line.
        $email = substr((string) preg_replace('/[^\x21-\x7e]/', '?', $email), 0, 100);

        $kind = $event->guard === 'mailbox' ? 'mailbox' : 'console';

        Log::channel('stack')->warning(sprintf('emailsquire auth failure kind=%s user=%s ip=%s', $kind, $email, request()->ip()));
    }
}
