<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword;

/** Preserve Laravel token lifecycle while verifying exactly once on every setup/reset attempt. */
final class ConsoleTokenRepository extends DatabaseTokenRepository
{
    public function exists(CanResetPassword $user, #[\SensitiveParameter] $token): bool
    {
        $record = $this->getTable()->where('email', $user->getEmailForPasswordReset())->first();
        if ($record === null || ! is_string($record->created_at) || ! is_string($record->token)
            || $this->tokenExpired($record->created_at)) {
            DummyHash::check($token);

            return false;
        }

        return $this->hasher->check($token, $record->token);
    }
}
