<?php

namespace App\Http\Requests;

use App\MailServer\SelfService;
use Illuminate\Foundation\Http\FormRequest;

class UpdateDomainSelfServiceRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [];
        foreach (array_keys(SelfService::DEFAULTS) as $field) {
            // Strict booleans only: "yes" or 2 are a 422, not a guess.
            $rules[$field] = ['sometimes', 'boolean:strict'];
        }

        return $rules;
    }

    /** @return array{forwarding?: bool, aliases?: bool, external_forwarding?: bool} */
    public function switches(): array
    {
        $values = [];
        foreach (array_keys(SelfService::DEFAULTS) as $field) {
            if ($this->has($field)) {
                $values[$field] = $this->boolean($field);
            }
        }

        return $values;
    }
}
