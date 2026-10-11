<?php

namespace App\Http\Requests\Portal;

use App\Rules\MailboxPassword;
use Illuminate\Foundation\Http\FormRequest;

/** PUT /portal/password: the mailbox's current password and the new one, which follows the mailbox rules. */
class ChangePasswordRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            /** The mailbox's password now; the mail server checks it. */
            'current_password' => ['required', 'string', 'max:1024'],
            /** 12 or more characters, at most 72 bytes, strength 3 or more, and not in a known breach when that check is on. */
            'password' => ['required', 'string', 'confirmed', new MailboxPassword],
        ];
    }

    public function currentPassword(): string
    {
        return $this->string('current_password')->toString();
    }

    public function newPassword(): string
    {
        return $this->string('password')->toString();
    }
}
