<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * The console account's password check on POST /auth/login (Fortify::authenticateUsing(), and first
 * in SignInRouting). The unknown-email and deactivated paths cost exactly one Hash::check (DummyHash),
 * like the known-user path. One request checks once: the pipeline asks up to three times for a user
 * without two-factor (SignInRouting, RedirectIfTwoFactorAuthenticatable, AttemptToAuthenticate), and
 * the later calls reuse the first one's answer instead of hashing again.
 */
final class ConsoleCredentials
{
    /** Request attribute holding the answer of this request's check: the User, or false. */
    private const CHECKED = 'emailsquire.console_credentials';

    public static function check(Request $request): ?User
    {
        $checked = $request->attributes->get(self::CHECKED);
        if ($checked instanceof User || $checked === false) {
            return $checked ?: null;
        }

        $user = self::verify($request);
        $request->attributes->set(self::CHECKED, $user ?? false);

        return $user;
    }

    private static function verify(Request $request): ?User
    {
        $user = User::where('email', Str::lower($request->string('email')->toString()))->first();
        $password = $request->string('password')->toString();

        // A deactivated user gets the wrong-password answer after the same one hash check, so the
        // response says nothing about the account's state.
        if ($user === null || $user->password_set_at === null || ! $user->isActive()) {
            DummyHash::check($password);

            return null;
        }

        return Hash::check($password, $user->password) ? $user : null;
    }
}
