<?php

namespace App\Auth;

use App\Http\Controllers\Auth\MeController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

/**
 * A console sign-in without the two-factor step: `{"kind": "console"}` plus the `me` payload. A mailbox
 * sign-in answers `{"kind": "mailbox"}` from SignInRouting and never gets here.
 */
class LoginResponse implements LoginResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): JsonResponse
    {
        $user = Auth::guard('web')->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return response()->json(['kind' => 'console'] + MeController::payload($user));
    }
}
