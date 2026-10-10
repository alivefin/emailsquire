<?php

namespace App\Http\Requests;

use App\MailServer\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class DestroyDomainRequest extends FormRequest
{
    /** @return array<string, never> */
    public function rules(): array
    {
        return [];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $domain = $this->route('domain');
            $name = $domain instanceof Domain ? $domain->name : '';
            $given = $this->input('confirm_name');

            // Exact, case-sensitive match: typing the name is the deliberate act. A missing or
            // non-string value fails the same way.
            if ($this->input('confirm_name') !== $name) {
                $validator->errors()->add('confirm_name', "Type {$name} to confirm.");
            }
        }];
    }
}
