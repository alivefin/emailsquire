<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PutForwardingRequest extends FormRequest
{
    private ?EmailAddress $source = null;

    /** @var list<EmailAddress> */
    private array $targets = [];

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'targets' => ['required', 'array', 'min:1', 'max:50'],
            'keep_copy' => ['sometimes', 'boolean'],
            'redirect_mailbox' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'targets.required' => 'Add at least one destination.',
            'targets.array' => 'Add at least one destination.',
            'targets.min' => 'Add at least one destination.',
            'targets.max' => 'A source can forward to at most 50 destinations.',
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $domain = $this->route('domain');
            if (! $domain instanceof Domain) {
                throw new \LogicException('Route has no domain.');
            }
            $normalizer = app(AddressNormalizer::class);

            try {
                $this->source = $normalizer->addressIn($this->routeLocal(), $normalizer->domain($domain->name));
            } catch (InvalidAddress $e) {
                $validator->errors()->add('local', $e->getMessage());

                return;
            }

            $raw = $this->input('targets');
            if ($validator->errors()->has('targets') || ! is_array($raw)) {
                return;
            }

            $seen = [];
            foreach (array_values($raw) as $i => $value) {
                try {
                    if (! is_string($value)) {
                        throw new InvalidAddress('empty');
                    }
                    $target = $normalizer->address($value);
                } catch (InvalidAddress) {
                    $shown = is_string($value) ? $value : '';
                    $validator->errors()->add("targets.{$i}", "“{$shown}” is not a complete address.");

                    continue;
                }

                if ($target->address() === $this->source->address()) {
                    $validator->errors()->add("targets.{$i}", 'An address can’t deliver to itself.');

                    continue;
                }
                $seen[$target->address()] ??= $target;
            }
            $this->targets = array_values($seen);
        }];
    }

    public function source(): EmailAddress
    {
        return $this->source ?? throw new \LogicException('Request was not validated.');
    }

    /** @return list<EmailAddress> */
    public function targets(): array
    {
        return $this->targets;
    }

    public function keepCopy(): bool
    {
        return $this->boolean('keep_copy');
    }

    public function redirectMailbox(): bool
    {
        return $this->boolean('redirect_mailbox');
    }

    private function routeLocal(): string
    {
        $local = $this->route('local');

        return is_string($local) ? $local : '';
    }
}
