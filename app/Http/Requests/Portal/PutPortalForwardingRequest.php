<?php

namespace App\Http\Requests\Portal;

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Exceptions\ClaimRefused;
use App\MailServer\SelfService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** PUT /portal/forwarding: where the owner's mailbox forwards to, and whether it keeps a copy. */
class PutPortalForwardingRequest extends FormRequest
{
    /** @var array<int, EmailAddress> keyed by position in the request */
    private array $targets = [];

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            /** At most 10 addresses (`+` allowed), never the mailbox itself; an empty list turns forwarding off. */
            'targets' => ['present', 'array'],
            /** Keep a copy in the mailbox as well; ignored when `targets` is empty. */
            'keep_copy' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $raw = $this->input('targets');
            if ($validator->errors()->has('targets') || ! is_array($raw)) {
                return;
            }
            // Before looking at each one: the answer is the same, and a long list costs nothing.
            if (count($raw) > SelfService::MAX_TARGETS) {
                throw new ClaimRefused('too_many_targets', __('emailsquire.portal.too_many_targets', ['max' => SelfService::MAX_TARGETS]), 'targets');
            }

            $own = strtolower($this->owner()->address);
            $normalizer = app(AddressNormalizer::class);
            $seen = [];
            foreach (array_values($raw) as $i => $value) {
                try {
                    if (! is_string($value)) {
                        throw new InvalidAddress('empty');
                    }
                    $target = $normalizer->target($value);
                } catch (InvalidAddress) {
                    $validator->errors()->add("targets.{$i}", __('emailsquire.forwarding.target_incomplete', ['value' => is_string($value) ? $value : '']));

                    continue;
                }

                // The mailbox itself is `keep_copy`, not a target.
                if ($target->address() === $own) {
                    $validator->errors()->add("targets.{$i}", __('emailsquire.forwarding.target_self'));

                    continue;
                }
                if (! isset($seen[$target->address()])) {
                    $seen[$target->address()] = true;
                    $this->targets[$i] = $target;
                }
            }
        }];
    }

    /** @return array<int, EmailAddress> normalised and de-duplicated, keyed by position in the request */
    public function targets(): array
    {
        return $this->targets;
    }

    public function keepCopy(): bool
    {
        return $this->boolean('keep_copy');
    }

    private function owner(): MailboxPrincipal
    {
        $owner = $this->user('mailbox');

        return $owner instanceof MailboxPrincipal ? $owner : throw new \LogicException('No mailbox is signed in.');
    }
}
