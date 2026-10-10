<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Support\Facades\Mail;

/** Entry point for mail the app sends about a user's account. */
final class Notify
{
    /**
     * Queues a security notice to the user's address, in their language, naming the current request's
     * IP. A change made on the server's console ($fromRequest false) names no IP address.
     */
    public static function security(User $user, string $kind, bool $fromRequest = true): void
    {
        Mail::to($user)
            ->locale($user->locale)
            ->queue(new SecurityNotice($user, $kind, $fromRequest ? request()->ip() : null));
    }
}
