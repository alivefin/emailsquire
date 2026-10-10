<?php

namespace App\Http\Requests;

use App\Models\DomainGrant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateDomainAccessRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', Rule::in(DomainGrant::ROLES)],
        ];
    }

    /** @return 'manager'|'viewer' */
    public function role(): string
    {
        return $this->string('role')->toString() === 'manager' ? 'manager' : 'viewer';
    }
}
