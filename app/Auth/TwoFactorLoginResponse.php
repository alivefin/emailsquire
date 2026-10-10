<?php

namespace App\Auth;

use App\Http\Controllers\Auth\MeController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;

/** After a passed two-factor challenge: the same `me` payload as a password-only sign-in (LoginResponse). */
class TwoFactorLoginResponse implements TwoFactorLoginResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): JsonResponse
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return response()->json(MeController::payload($user));
    }
}
