<?php

namespace App\Http\Requests\Portal;

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** POST /portal/aliases: a new address on the owner's own domain that delivers to their mailbox. */
class StoreAliasRequest extends FormRequest
{
    private ?EmailAddress $source = null;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            /** The part before `@`; the domain is always the mailbox's own. Letters, numbers, dots, hyphens and underscores, no `+`. */
            'local' => ['required', 'string', 'max:100'],
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('local')) {
                return;
            }
            $owner = $this->user('mailbox');
            if (! $owner instanceof MailboxPrincipal) {
                throw new \LogicException('No mailbox is signed in.');
            }
            $normalizer = app(AddressNormalizer::class);

            try {
                $this->source = $normalizer->addressIn($this->string('local')->toString(), $normalizer->domain($owner->domainName));
            } catch (InvalidAddress $e) {
                $validator->errors()->add('local', $e->getMessage());
            }
        }];
    }

    public function source(): EmailAddress
    {
        return $this->source ?? throw new \LogicException('Request was not validated.');
    }
}
