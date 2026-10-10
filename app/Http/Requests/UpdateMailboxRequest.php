<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMailboxRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'quota_bytes' => ['sometimes', 'required', 'integer', 'min:'.StoreMailboxRequest::MIN_QUOTA, 'max:'.StoreMailboxRequest::MAX_QUOTA],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    public function quotaBytes(): ?int
    {
        return $this->has('quota_bytes') ? $this->integer('quota_bytes') : null;
    }

    public function hasDisplayName(): bool
    {
        return $this->has('display_name');
    }

    public function displayName(): ?string
    {
        $name = $this->input('display_name');

        return is_string($name) && $name !== '' ? $name : null;
    }
}
