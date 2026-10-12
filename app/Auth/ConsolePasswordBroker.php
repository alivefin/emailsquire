<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Auth\CanResetPassword;

/** Pad unknown-email setup/reset requests, which Laravel otherwise refuses before checking a token. */
final class ConsolePasswordBroker extends PasswordBroker
{
    /** @param array<string, mixed> $credentials */
    protected function validateReset(#[\SensitiveParameter] array $credentials): CanResetPassword|string
    {
        $user = $this->getUser($credentials);
        $token = $credentials['token'] ?? '';
        if (! is_string($token)) {
            throw new \InvalidArgumentException('Password token must be a string');
        }
        if ($user === null) {
            DummyHash::check($token);

            return self::INVALID_USER;
        }

        return $this->tokens->exists($user, $token) ? $user : self::INVALID_TOKEN;
    }
}
