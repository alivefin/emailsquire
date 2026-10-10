<?php

namespace App\Auth;

use Closure;
use Illuminate\Http\Request;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession as FortifyPrepare;
use Laravel\Fortify\Fortify;

/** Fortify's last sign-in step, which also clears the `throttle:login` count (LoginThrottle). */
class PrepareAuthenticatedSession extends FortifyPrepare
{
    /**
     * @param  Request  $request
     * @param  Closure  $next
     * @return mixed
     */
    public function handle($request, $next)
    {
        LoginThrottle::clear($request->string(Fortify::username())->toString(), $request);

        return parent::handle($request, $next);
    }
}
