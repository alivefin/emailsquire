<?php

namespace App\Http\Requests;

use App\MailServer\Models\Domain;
use App\Models\Invitation;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;
use LogicException;

/**
 * An invitation from the Users screen (POST /users/invitations): to be a server admin, or to a role on
 * a domain named in the body.
 */
class StoreUserInvitationRequest extends StoreDomainInvitationRequest
{
    private ?Domain $domain = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'role' => ['required', 'string', Rule::in(Invitation::ROLES)],
            /** The domain (ASCII or unicode) for a `manager` or `viewer`; leave it out for a `server_admin`. */
            'domain' => ['nullable', 'string'],
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->has('role')) {
                return;
            }
            $name = $this->input('domain');
            $given = is_string($name) && trim($name) !== '';
            if ($this->role() === 'server_admin') {
                if ($given) {
                    $validator->errors()->add('domain', trans()->string('validation.prohibited', ['attribute' => 'domain']));
                }

                return;
            }
            if (! $given) {
                $validator->errors()->add('domain', trans()->string('emailsquire.invitation.domain_required'));

                return;
            }
            $this->domain = Domain::named($name);
            if ($this->domain === null) {
                $validator->errors()->add('domain', trans()->string('emailsquire.invitation.domain_unknown'));
            }
        }];
    }

    /** @return 'server_admin'|'manager'|'viewer' */
    public function role(): string
    {
        return match ($this->string('role')->toString()) {
            'server_admin' => 'server_admin',
            'manager' => 'manager',
            default => 'viewer',
        };
    }

    /** The domain for a domain role; null for a server admin. */
    public function domain(): ?Domain
    {
        if ($this->role() === 'server_admin') {
            return null;
        }

        return $this->domain ?? throw new LogicException('Request was not validated.');
    }
}
