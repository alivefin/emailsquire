<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\DomainName;
use App\MailServer\Address\EmailAddress;

/**
 * PUT /domains/{domain}/forwarding/@: the domain's catch-all. Targets are checked as for any other
 * forwarding (1 to 50, `+` allowed, de-duplicated); `keep_copy` may only be false, since a catch-all
 * has no mailbox, and `redirect_mailbox` does not apply.
 */
class PutCatchAllRequest extends PutForwardingRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'targets' => ['required', 'array', 'min:1', 'max:50'],
            'keep_copy' => ['sometimes', 'boolean', 'declined'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return parent::messages() + [
            'keep_copy.declined' => __('emailsquire.forwarding.catch_all_keep_copy'),
        ];
    }

    public function keepCopy(): bool
    {
        return false;
    }

    public function redirectMailbox(): bool
    {
        return false;
    }

    protected function sourceIn(DomainName $domain, AddressNormalizer $normalizer): EmailAddress
    {
        return new EmailAddress('', $domain);
    }
}
