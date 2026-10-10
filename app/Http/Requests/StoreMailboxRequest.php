<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Models\Domain;
use App\Rules\MailboxPassword;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreMailboxRequest extends FormRequest
{
    public const MIN_QUOTA = 1048576;

    public const MAX_QUOTA = 1099511627776;

    private ?EmailAddress $address = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'local' => ['required', 'string'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['required', 'string', new MailboxPassword],
            'quota_bytes' => ['required', 'integer', 'min:'.self::MIN_QUOTA, 'max:'.self::MAX_QUOTA],
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('local')) {
                return;
            }
            $domain = $this->route('domain');
            if (! $domain instanceof Domain) {
                throw new \LogicException('Route has no domain.');
            }
            $normalizer = app(AddressNormalizer::class);
            try {
                $this->address = $normalizer->addressIn($this->string('local')->value(), $normalizer->domain($domain->name));
            } catch (InvalidAddress $e) {
                $validator->errors()->add('local', $e->getMessage());
            }
        }];
    }

    public function address(): EmailAddress
    {
        return $this->address ?? throw new \LogicException('Request was not validated.');
    }

    public function displayName(): ?string
    {
        $name = $this->input('display_name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
