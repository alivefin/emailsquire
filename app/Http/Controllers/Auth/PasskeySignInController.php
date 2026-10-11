<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginThrottle;
use App\Auth\Passkeys;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Passwordless sign-in with a passkey (passkeys spec decisions 2 and 6). One gesture with user
 * verification is both factors, so it signs in without the two-factor step. The options name no user
 * (discoverable credentials), so they tell nothing about which accounts exist.
 *
 * A finished sign-in takes the same steps as a password one: the session id and CSRF token are
 * regenerated, a portal session in this browser ends (SingleGuardSession), RecordSignIn drops any
 * step-up window, pending two-factor sign-in and ceremony options and stamps `last_login_at`, and the
 * `login` limiter of the user's email and this IP is cleared.
 */
class PasskeySignInController extends Controller
{
    /**
     * Assertion options for a passwordless sign-in, for `navigator.credentials.get()`.
     *
     * `allowCredentials` is empty and the challenge is kept in the session, good for one attempt within 5 minutes.
     *
     * @response array<string, mixed>
     */
    public function options(Request $request, Passkeys $passkeys): JsonResponse
    {
        return response()->json($passkeys->assertionOptions($request->session(), Passkeys::SIGN_IN))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * Signs in with a passkey.
     *
     * Answers as POST /auth/login does for a console sign-in: `kind: "console"` and the `me` payload.
     * Every refusal is the same 422 `passkey_failed`; a passkey whose signature counter didn't go up is
     * turned off and answers `passkey_cloned`.
     *
     * @response array{kind: 'console', id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, second_factor: bool, passkeys: int, two_factor_required: bool, managers_can_share: bool, landing: string|null}
     */
    public function store(Request $request, Passkeys $passkeys): Response
    {
        $request->validate([
            /**
             * The `PublicKeyCredential` from `navigator.credentials.get()` as JSON (`id`, `rawId`, `type`,
             * `response.clientDataJSON`, `response.authenticatorData`, `response.signature`,
             * `response.userHandle`), binary values base64url.
             *
             * @var array<string, mixed>
             */
            'credential' => ['required', 'array'],
        ]);
        /** @var array<array-key, mixed> $credential */
        $credential = $request->input('credential');

        $user = $passkeys->verifyAssertion($request->session(), Passkeys::SIGN_IN, null, $credential);

        LoginThrottle::clear($user->email, $request);
        // Never remembered: see the remember-me note in AppServiceProvider::bootConsoleSignIn().
        Auth::guard('web')->login($user, false);
        $request->session()->regenerate();

        return app(LoginResponse::class)->toResponse($request);
    }
}
