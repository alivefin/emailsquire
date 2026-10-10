<?php

namespace App\Auth;

use App\Http\Controllers\Auth\MeController;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
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
