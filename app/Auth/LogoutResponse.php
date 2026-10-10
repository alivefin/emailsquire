<?php

namespace App\Auth;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;

class LogoutResponse implements LogoutResponseContract
{
    /** @param  Request  $request */
    public function toResponse($request): Response
    {
        return response()->noContent();
    }
}
