<?php

namespace App\Rules;

use App\MailServer\PasswordStrength;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * bcrypt ignores everything past byte 72, so longer passwords are refused rather than truncated; and
 * it refuses a NUL byte outright, so that is refused here as a 422 rather than a 500.
 */
class MailboxPassword implements ValidationRule
{
    public function validate(string $attribute, #[\SensitiveParameter] mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail(__('emailsquire.mailbox.password_weak'));

            return;
        }
        if (str_contains($value, "\0")) {
            $fail(__('emailsquire.mailbox.password_nul'));

            return;
        }
        if (strlen($value) > 72) {
            $fail(__('emailsquire.mailbox.password_too_long'));

            return;
        }
        if (mb_strlen($value) < 12 || PasswordStrength::score($value) < 3) {
            $fail(__('emailsquire.mailbox.password_weak'));

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
