<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** A setup link or a reset link: the token, its email and the new password. */
class SetupRequest extends FormRequest
{
    /** @return array<string, array<int, ValidationRule|string|Password>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => self::passwordRules(),
        ];
    }

    /**
     * The rules for every new console password (setup, reset, change): 12 or more characters, with a
     * matching `password_confirmation`, and not in a known breach when `emailsquire.hibp` is on.
     *
     * @return array<int, string|Password>
     */
    public static function passwordRules(): array
    {
        $password = Password::min(12);
        if (config('emailsquire.hibp')) {
            $password = $password->uncompromised();
        }

        return ['required', 'string', 'confirmed', $password];
    }
}
