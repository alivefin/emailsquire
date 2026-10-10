<?php

namespace App\Rules;

use App\MailServer\PasswordStrength;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** bcrypt ignores everything past byte 72, so longer passwords are refused rather than truncated. */
class MailboxPassword implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('Too weak — use 12+ characters with a number or symbol.');

            return;
        }
        if (strlen($value) > 72) {
            $fail('Use at most 72 bytes.');

            return;
        }
        if (mb_strlen($value) < 12 || PasswordStrength::score($value) < 3) {
            $fail('Too weak — use 12+ characters with a number or symbol.');

            return;
        }
        if (config('emailsquire.hibp') === true) {
            $check = Validator::make(['password' => $value], ['password' => [Password::min(12)->uncompromised()]]);
            if ($check->fails()) {
                $fail((string) $check->errors()->first('password'));
            }
        }
    }
}
