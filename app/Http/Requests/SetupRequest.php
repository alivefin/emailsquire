<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class SetupRequest extends FormRequest
{
    /** @return array<string, array<int, ValidationRule|string|Password>> */
    public function rules(): array
    {
        $password = Password::min(12);
        if (config('emailsquire.hibp')) {
            $password = $password->uncompromised();
        }

        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', $password],
        ];
    }
}
