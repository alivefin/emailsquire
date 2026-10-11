<?php

namespace App\Http\Requests;

use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

/**
 * POST /auth/login's body: Fortify's rules plus the optional `mailbox` flag (bound in place of
 * Fortify's LoginRequest, AppServiceProvider). With `mailbox: true` the console account is not tried:
 * the sign-in is a mailbox one or the usual refusal (SignInRouting).
 */
class LoginRequest extends FortifyLoginRequest
{
    /** @return array<string, list<string>> Fortify's rules, as its LoginRequest has them, and `mailbox` */
    public function rules(): array
    {
        return [
            Fortify::username() => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes'],
            'mailbox' => ['sometimes', 'boolean'],
        ];
    }
}
