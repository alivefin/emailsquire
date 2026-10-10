<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** One-time setup links for console users: a password-reset token on the `admin_setup` broker. */
class AdminSetup
{
    /** Creates (replacing any earlier) setup token for the user and returns its plaintext. */
    public function issue(User $user): string
    {
        return $this->broker()->createToken($user);
    }

    /**
     * Sets the user's first password if the token is valid for that email, and spends the token.
     *
     * @throws ValidationException
     */
    public function complete(string $email, string $token, string $password): User
    {
        $completed = null;

        $status = $this->broker()->reset(
            ['email' => Str::lower($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password) use (&$completed): void {
                $user->forceFill(['password' => $password, 'password_set_at' => now(), 'remember_token' => null])->save();
                $completed = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $completed instanceof User) {
            // One message for a wrong, spent or expired token, and for an unknown email.
            throw ValidationException::withMessages(['token' => [__('emailsquire.setup.invalid_link')]]);
        }

        return $completed;
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker('admin_setup');
    }
}
