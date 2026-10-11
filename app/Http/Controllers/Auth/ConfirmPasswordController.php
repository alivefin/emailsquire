<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginThrottle;
use App\Auth\Passkeys;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireStepUp;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

/**
 * Step-up: the signed-in user proves it is still them with their password, a TOTP code once two-factor
 * is confirmed, or one of their usable passkeys. Success opens a 300 s window for RequireStepUp routes;
 * a wrong password, code or passkey writes the same fail2ban line as a failed sign-in.
 */
class ConfirmPasswordController extends Controller
{
    public function __invoke(Request $request, TwoFactorAuthenticationProvider $provider, Passkeys $passkeys): Response
    {
        if ($request->has('passkey')) {
            LoginThrottle::hitPasskey($request);
        }
        $request->validate([
            'password' => ['required_without_all:code,passkey', 'prohibits:code,passkey', 'string'],
            'code' => ['required_without_all:password,passkey', 'prohibits:passkey', 'string'],
            /**
             * The `PublicKeyCredential` from `navigator.credentials.get()` with the options of POST
             * /auth/confirm-password/passkey-options, as JSON, instead of `password`.
             *
             * @var array<string, mixed>
             */
            'passkey' => ['required_without_all:password,code', 'array'],
        ]);

        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        if ($request->has('passkey')) {
            /** @var array<array-key, mixed> $credential */
            $credential = $request->input('passkey');
            // Refuses (422 passkey_failed, fail2ban line) anything but one of this user's usable passkeys.
            $passkeys->verifyAssertion($request->session(), Passkeys::STEP_UP, $user, $credential);
        } else {
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
        }

        $request->session()->put(RequireStepUp::SESSION_KEY, now()->getTimestamp());

        return response()->noContent();
    }

    /**
     * Assertion options for a step-up with one of the signed-in user's usable passkeys, for `navigator.credentials.get()`.
     *
     * 422 `passkey_failed` (on `passkey`) when the user has no usable passkey. Counts against step-up's limit.
     *
     * @response array<string, mixed>
     */
    public function passkeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return response()->json($passkeys->assertionOptions($request->session(), Passkeys::STEP_UP, $user))
            ->header('Cache-Control', 'no-store');
    }
}
