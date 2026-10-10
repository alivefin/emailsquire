<?php

namespace App\Http\Controllers\Auth;

use App\Auth\LoginThrottle;
use App\Auth\RedirectIfTwoFactorAuthenticatable;
use App\Auth\TwoFactorAccount;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

/**
 * Second sign-in step for a user with confirmed two-factor. POST /auth/login has checked the password
 * and left `login.id` and `login.at` in the session (RedirectIfTwoFactorAuthenticatable); within five
 * minutes, this takes a TOTP code or a recovery code and signs that user in. A recovery code works once: it is removed, not replaced, so
 * `recovery_codes_left` counts down and the user regenerates them after step-up.
 */
class TwoFactorChallengeController extends Controller
{
    /**
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, two_factor_required: bool, managers_can_share: bool, landing: string|null}
     */
    public function __invoke(Request $request, StatefulGuard $guard, TwoFactorAuthenticationProvider $provider, TwoFactorAccount $account): Response
    {
        $request->validate([
            /** The 6-digit code from the authenticator app. */
            'code' => ['required_without:recovery_code', 'prohibits:recovery_code', 'string'],
            /** One of the user's recovery codes, instead of `code`. */
            'recovery_code' => ['required_without:code', 'string'],
        ]);
        $field = $request->has('code') ? 'code' : 'recovery_code';

        if ($request->session()->has('login.id') && ! $this->pendingIsFresh($request)) {
            $request->session()->forget(RedirectIfTwoFactorAuthenticatable::PENDING_KEYS);
            throw ValidationException::withMessages([$field => [trans()->string('emailsquire.two_factor.pending_expired')]]);
        }

        $user = $this->pendingUser($request);
        // Deactivated since the password step: the pending sign-in ends as if it had expired.
        if ($user !== null && ! $user->isActive()) {
            $request->session()->forget(RedirectIfTwoFactorAuthenticatable::PENDING_KEYS);
            throw ValidationException::withMessages([$field => [trans()->string('emailsquire.two_factor.pending_expired')]]);
        }
        if ($user === null) {
            throw ValidationException::withMessages([$field => [trans()->string('emailsquire.two_factor.no_pending_sign_in')]]);
        }

        $passed = $field === 'code'
            ? $this->validCode($user, $request->string('code')->toString(), $provider)
            : $account->consumeRecoveryCode($user, $request->string('recovery_code')->toString());

        if (! $passed) {
            // Same line as a failed sign-in (AuthFailureLogger), naming the pending user; never the code.
            event(new Failed('web', $user, ['email' => $user->email]));

            throw ValidationException::withMessages([$field => [trans()->string(
                $field === 'code' ? 'emailsquire.two_factor.code_invalid' : 'emailsquire.two_factor.recovery_code_invalid',
            )]]);
        }

        $request->session()->forget(RedirectIfTwoFactorAuthenticatable::PENDING_KEYS);
        // As at a password-only sign-in (PrepareAuthenticatedSession): the sign-in is finished.
        LoginThrottle::clear($user->email, $request);
        // Never remembered: see the remember-me note in AppServiceProvider::bootConsoleSignIn().
        $guard->login($user, false);
        $request->session()->regenerate();

        return app(TwoFactorLoginResponse::class)->toResponse($request);
    }

    /** A pending sign-in counts for five minutes from the password step (RedirectIfTwoFactorAuthenticatable). */
    private function pendingIsFresh(Request $request): bool
    {
        $at = $request->session()->get(RedirectIfTwoFactorAuthenticatable::PENDING_AT);

        return is_int($at) && now()->getTimestamp() - $at <= RedirectIfTwoFactorAuthenticatable::PENDING_MAX_AGE_SECONDS;
    }

    /** The user whose password was just checked, if they still can and must pass two-factor. */
    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get('login.id');
        $user = is_int($id) || is_string($id) ? User::query()->find($id) : null;

        return $user instanceof User && $user->password_set_at !== null && $user->hasEnabledTwoFactorAuthentication() ? $user : null;
    }

    /** The provider remembers each accepted code, so the same code is refused within its window (replay). */
    private function validCode(User $user, string $code, TwoFactorAuthenticationProvider $provider): bool
    {
        $secret = Fortify::currentEncrypter()->decrypt((string) $user->two_factor_secret);

        return is_string($secret) && $provider->verify($secret, $code);
    }
}
