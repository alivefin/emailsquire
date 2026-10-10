<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
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
            $unicode = $this->unicode($name);
            $given = $this->input('confirm_name');

            // Exact, case-sensitive match on the stored (punycode) name or its unicode form: typing the
            // name is the deliberate act. A missing or non-string value fails the same way.
            if ($name === '' || ! is_string($given) || ($given !== $name && $given !== $unicode)) {
                $shown = $unicode === $name ? $name : "{$unicode} (or {$name})";
                $validator->errors()->add('confirm_name', __('emailsquire.domain.confirm_name', ['name' => $shown]));
            }
        }];
    }

    private function unicode(string $name): string
    {
        try {
            return app(AddressNormalizer::class)->domain($name)->unicode;
        } catch (InvalidAddress) {
            return $name; // restored data may hold names we would not accept today
        }
    }
}
