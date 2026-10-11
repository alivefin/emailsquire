<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LogoutResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signs out a console or a portal session (`auth:web,mailbox` picks the guard it holds): Fortify's
 * own sign-out, which knows only `web`, for either guard.
 */
#[Group('AuthenticatedSession')]
class LogoutController extends Controller
{
    /** Destroy an authenticated session */
    public function __invoke(Request $request): Response
    {
        Auth::guard()->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return app(LogoutResponse::class)->toResponse($request);
    }
}
