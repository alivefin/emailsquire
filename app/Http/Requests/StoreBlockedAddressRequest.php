<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreBlockedAddressRequest extends FormRequest
{
    private ?EmailAddress $parsed = null;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'address' => ['required', 'string'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('address')) {
                return;
            }
            try {
                $this->parsed = app(AddressNormalizer::class)->address($this->string('address')->value());
            } catch (InvalidAddress $e) {
                $validator->errors()->add('address', $e->getMessage());
            }
        }];
    }

    public function emailAddress(): EmailAddress
    {
        return $this->parsed ?? throw new \LogicException('Request was not validated.');
    }

    public function reason(): ?string
    {
        $reason = $this->input('reason');

        return is_string($reason) && trim($reason) !== '' ? $reason : null;
    }
}
