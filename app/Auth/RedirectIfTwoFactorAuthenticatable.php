<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Laravel\Fortify\Actions\RedirectIfTwoFactorAuthenticatable as FortifyRedirect;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fortify's two-factor step of the sign-in pipeline, plus two things for the pending sign-in it leaves
 * in the session: a new session id (the password was just proven, as at a full sign-in) and `login.at`,
 * so TwoFactorChallengeController can refuse a pending sign-in older than five minutes.
 */
class RedirectIfTwoFactorAuthenticatable extends FortifyRedirect
{
    public const PENDING_AT = 'login.at';

    public const PENDING_MAX_AGE_SECONDS = 300;

    /**
     * @param  Request  $request
     * @param  mixed  $user
     * @return Response
     */
    protected function twoFactorChallengeResponse($request, $user)
    {
        $request->session()->regenerate();
        $response = parent::twoFactorChallengeResponse($request, $user);
        $request->session()->put(self::PENDING_AT, now()->getTimestamp());

        return $response;
    }
}
