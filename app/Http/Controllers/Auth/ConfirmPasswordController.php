<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireStepUp;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Step-up: the signed-in user proves it is still them with their password, or with a TOTP code once
 * two-factor is confirmed. Success opens a 300 s window for RequireStepUp routes; a wrong password or
 * code writes the same fail2ban line as a failed sign-in.
 */
class ConfirmPasswordController extends Controller
{
    public function __invoke(Request $request, TwoFactorAuthenticationProvider $provider): Response
    {
        $request->validate([
            'password' => ['required_without:code', 'prohibits:code', 'string'],
            'code' => ['required_without:password', 'string'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($request->has('password')) {
            $field = 'password';
            $message = trans()->string('auth.password');
            $confirmed = Hash::check($request->string('password')->toString(), $user->password);
        } else {
            $field = 'code';
            $message = trans()->string('emailsquire.step_up.code_invalid');
            if ($user->two_factor_secret === null || $user->two_factor_confirmed_at === null) {
                throw ValidationException::withMessages(['code' => [trans()->string('emailsquire.step_up.no_two_factor')]]);
            }
            $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);
            $confirmed = is_string($secret) && $provider->verify($secret, $request->string('code')->toString());
        }

        if (! $confirmed) {
            // Same line as a failed sign-in (AuthFailureLogger); never the password or code.
            event(new Failed('web', $user, ['email' => $user->email]));

            throw ValidationException::withMessages([$field => [$message]]);
        }

        $request->session()->put(RequireStepUp::SESSION_KEY, now()->getTimestamp());

        return response()->noContent();
    }
}
