<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirect;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use LogicException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's two-factor step of the sign-in pipeline, plus two things for the pending sign-in it leaves
 * in the session: a new session id (the password was just proven, as at a full sign-in) and `login.at`,
 * so TwoFactorChallengeController can refuse a pending sign-in older than five minutes. A setup link
 * for a user with confirmed two-factor ends the same way (SetupController).
 */
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirect
{
    public const PENDING_AT = 'login.at';

    public const PENDING_MAX_AGE_SECONDS = 300;

    /** Everything a pending sign-in keeps in the session. */
    public const PENDING_KEYS = ['login.id', 'login.remember', self::PENDING_AT];

    /**
     * Leaves $user's sign-in pending under a new session id and answers `{"two_factor": true}`: the
     * session holds `login.id`, `login.remember` (always false, see the remember-me note in
     * AppServiceProvider::bootConsoleSignIn()) and `login.at` for POST /auth/two-factor-challenge.
     */
    public static function startPendingSignIn(Request $request, User $user): JsonResponse
    {
        $request->session()->regenerate();
        $request->session()->put(array_combine(self::PENDING_KEYS, [$user->getKey(), false, now()->getTimestamp()]));

        TwoFactorAuthenticationChallenged::dispatch($user);

        return response()->json(['two_factor' => true]);
    }

    /**
     * @param  Request  $request
     * @param  mixed  $user
     * @return Response
     */
    protected function twoFactorChallengeResponse($request, $user)
    {
        if (! $user instanceof User) {
            throw new LogicException('Fortify::authenticateUsing() answers App\\Models\\User only.');
        }

        return self::startPendingSignIn($request, $user);
    }
}
