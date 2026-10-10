<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\DomainName;
use App\MailServer\Address\InvalidAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDomainRequest extends FormRequest
{
    private ?DomainName $domain = null;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string']];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            try {
                $this->domain = app(AddressNormalizer::class)->domain($this->string('name')->value());
            } catch (InvalidAddress $e) {
                $validator->errors()->add('name', $e->getMessage());
            }
        }];
    }

    public function domain(): DomainName
    {
        return $this->domain ?? throw new \LogicException('Request was not validated.');
    }
}
