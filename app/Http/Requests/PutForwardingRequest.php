<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\DomainName;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Models\Domain;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class PutForwardingRequest extends FormRequest
{
    private ?EmailAddress $source = null;

    /** @var array<int, EmailAddress> keyed by the position of each address's first entry in the request */
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
            'targets.required' => __('emailsquire.forwarding.targets_required'),
            'targets.array' => __('emailsquire.forwarding.targets_required'),
            'targets.min' => __('emailsquire.forwarding.targets_required'),
            'targets.max' => __('emailsquire.forwarding.targets_max'),
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
                $this->source = $this->sourceIn($normalizer->domain($domain->name), $normalizer);
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
                    $target = $normalizer->target($value);
                } catch (InvalidAddress) {
                    $shown = is_string($value) ? $value : '';
                    $validator->errors()->add("targets.{$i}", __('emailsquire.forwarding.target_incomplete', ['value' => $shown]));

                    continue;
                }

                if ($target->address() === $this->source->address()) {
                    $validator->errors()->add("targets.{$i}", __('emailsquire.forwarding.target_self'));

                    continue;
                }
                $seen[$target->address()] ??= [$i, $target];
            }
            $this->targets = [];
            foreach ($seen as [$i, $target]) {
                $this->targets[$i] = $target;
            }
        }];
    }

    public function source(): EmailAddress
    {
        return $this->source ?? throw new \LogicException('Request was not validated.');
    }

    /** @return list<EmailAddress> */
    public function targets(): array
    {
        return array_values($this->targets);
    }

    /** @return array<int, EmailAddress> the targets keyed by their position in the request, for errors on `targets.N` */
    public function targetsByPosition(): array
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

    /** The source the route names, on the route's domain. */
    protected function sourceIn(DomainName $domain, AddressNormalizer $normalizer): EmailAddress
    {
        return $normalizer->addressIn($this->routeLocal(), $domain);
    }

    private function routeLocal(): string
    {
        $local = $this->route('local');

        return is_string($local) ? $local : '';
    }
}
