<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginThrottle;
use App\Auth\PasskeyRefused;
use App\Auth\Passkeys;
use App\Auth\RedirectIfTwoFactorAuthenticatable;
use App\Auth\TwoFactorAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second sign-in step for a user with a second factor (confirmed TOTP or a usable passkey). POST
 * /auth/login has checked the password and left `login.id` and `login.at` in the session
 * (RedirectIfTwoFactorAuthenticatable); within five minutes, this takes a TOTP code, a recovery code or
 * an assertion of one of that user's passkeys, and signs that user in. A recovery code works once: it
 * is removed, not replaced, so `recovery_codes_left` counts down and the user regenerates them after
 * step-up.
 */
class TwoFactorChallengeController extends Controller
{
    /**
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, second_factor: bool, passkeys: int, two_factor_required: bool, managers_can_share: bool, landing: string|null}
     */
    public function __invoke(Request $request, StatefulGuard $guard, TwoFactorAuthenticationProvider $provider, TwoFactorAccount $account, Passkeys $passkeys): Response
    {
        if ($request->has('passkey')) {
            // Shares the passwordless sign-in's ten a minute per IP, including malformed submissions.
            LoginThrottle::hitPasskey($request);
        }
        $request->validate([
            /** The 6-digit code from the authenticator app. */
            'code' => ['required_without_all:recovery_code,passkey', 'prohibits:recovery_code,passkey', 'string'],
            /** One of the user's recovery codes, instead of `code`. */
            'recovery_code' => ['required_without_all:code,passkey', 'prohibits:passkey', 'string'],
            /**
             * The `PublicKeyCredential` from `navigator.credentials.get()` with the options of POST
             * /auth/two-factor-challenge/passkey-options, as JSON, instead of `code`.
             *
             * @var array<string, mixed>
             */
            'passkey' => ['required_without_all:code,recovery_code', 'array'],
        ]);
        $field = match (true) {
            $request->has('code') => 'code',
            $request->has('recovery_code') => 'recovery_code',
            default => 'passkey',
        };
        // Keep the pending identity for the failure line even when pendingUser ends a stale sign-in.
        $id = $request->session()->get('login.id');
        try {
            $user = $this->pendingUser($request, $field);
        } catch (ValidationException $e) {
            if ($field !== 'passkey') {
                throw $e;
            }
            $who = is_int($id) || is_string($id) ? User::query()->find($id) : null;
            event(new Failed('web', $who, ['email' => $who->email ?? '', 'method' => 'passkey']));

            throw PasskeyRefused::failed('passkey');
        }

        if ($field === 'passkey') {
            /** @var array<array-key, mixed> $credential */
            $credential = $request->input('passkey');
            // Refuses (422 passkey_failed, fail2ban line) anything but one of this user's usable passkeys.
            $passkeys->verifyAssertion($request->session(), Passkeys::TWO_FACTOR, $user, $credential);
        } else {
            $passed = $field === 'code'
                ? $user->hasEnabledTwoFactorAuthentication() && $this->validCode($user, $request->string('code')->toString(), $provider)
                : $account->consumeRecoveryCode($user, $request->string('recovery_code')->toString());

            if (! $passed) {
                // Same line as a failed sign-in (AuthFailureLogger), naming the pending user; never the code.
                event(new Failed('web', $user, ['email' => $user->email]));

                throw ValidationException::withMessages([$field => [trans()->string(
                    $field === 'code' ? 'emailsquire.two_factor.code_invalid' : 'emailsquire.two_factor.recovery_code_invalid',
                )]]);
            }
        }

        $request->session()->forget(RedirectIfTwoFactorAuthenticatable::PENDING_KEYS);
        // As at a password-only sign-in (PrepareAuthenticatedSession): the sign-in is finished.
        LoginThrottle::clear($user->email, $request);
        // Never remembered: see the remember-me note in AppServiceProvider::bootConsoleSignIn().
        $guard->login($user, false);
        $request->session()->regenerate();

        return app(TwoFactorLoginResponse::class)->toResponse($request);
    }

    /**
     * Assertion options for one of the pending user's usable passkeys, for `navigator.credentials.get()`.
     *
     * 422 on `passkey` without a fresh pending sign-in, and `passkey_failed` when the user has no
     * usable passkey.
     *
     * @response array<string, mixed>
     */
    public function passkeyOptions(Request $request, Passkeys $passkeys): JsonResponse
    {
        $user = $this->pendingUser($request, 'passkey');

        return response()->json($passkeys->assertionOptions($request->session(), Passkeys::TWO_FACTOR, $user))
            ->header('Cache-Control', 'no-store');
    }

    /**
     * The user whose password was just checked, if their sign-in is still pending (five minutes), they
     * are active and they still have a second factor; otherwise 422 on $field, ending a stale one.
     *
     * @throws ValidationException
     */
    private function pendingUser(Request $request, string $field): User
    {
        if ($request->session()->has('login.id') && ! $this->pendingIsFresh($request)) {
            $request->session()->forget(RedirectIfTwoFactorAuthenticatable::PENDING_KEYS);
            throw ValidationException::withMessages([$field => [trans()->string('emailsquire.two_factor.pending_expired')]]);
        }

        $id = $request->session()->get('login.id');
        $user = is_int($id) || is_string($id) ? User::query()->find($id) : null;
        // Deactivated since the password step: the pending sign-in ends as if it had expired.
        if ($user instanceof User && ! $user->isActive()) {
            $request->session()->forget(RedirectIfTwoFactorAuthenticatable::PENDING_KEYS);
            throw ValidationException::withMessages([$field => [trans()->string('emailsquire.two_factor.pending_expired')]]);
        }
        if (! $user instanceof User || $user->password_set_at === null || ! $user->hasSecondFactor()) {
            throw ValidationException::withMessages([$field => [trans()->string('emailsquire.two_factor.no_pending_sign_in')]]);
        }

        return $user;
    }

    /** A pending sign-in counts for five minutes from the password step (RedirectIfTwoFactorAuthenticatable). */
    private function pendingIsFresh(Request $request): bool
    {
        $at = $request->session()->get(RedirectIfTwoFactorAuthenticatable::PENDING_AT);

        return is_int($at) && now()->getTimestamp() - $at <= RedirectIfTwoFactorAuthenticatable::PENDING_MAX_AGE_SECONDS;
    }

    /** The provider remembers each accepted code, so the same code is refused within its window (replay). */
    private function validCode(User $user, string $code, TwoFactorAuthenticationProvider $provider): bool
    {
        $secret = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);

        return is_string($secret) && $provider->verify($secret, $code);
    }
}
