<?php

namespace App\Http\Requests;

use App\MailServer\Models\Domain;
use App\Models\DomainGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/** A grant from the Users screen (POST /users/{user}/grants): a role on the domain named in the body. */
class StoreUserGrantRequest extends FormRequest
{
    private ?Domain $domain = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            /** The domain, ASCII or unicode. */
            'domain' => ['required', 'string'],
            'role' => ['required', 'string', Rule::in(DomainGrant::ROLES)],
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('domain')) {
                return;
            }
            $this->domain = Domain::named($this->input('domain'));
            if ($this->domain === null) {
                $validator->errors()->add('domain', trans()->string('emailsquire.invitation.domain_unknown'));
            }
        }];
    }

    public function domain(): Domain
    {
        return $this->domain ?? throw new LogicException('Request was not validated.');
    }

    /** @return 'manager'|'viewer' */
    public function role(): string
    {
        return $this->string('role')->toString() === 'manager' ? 'manager' : 'viewer';
    }
}
