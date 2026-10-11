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
            /** `false` stops the mailbox receiving mail. Needs the optional `enabled` column, else 422 `schema_extension_missing`. Forwarding rows from the address still apply. */
            'receiving' => ['sometimes', 'required', 'boolean'],
            /** `true` makes the mailbox send-only. Needs the optional `sendonly` column, else 422 `schema_extension_missing`. */
            'sendonly' => ['sometimes', 'required', 'boolean'],
        ];
    }

    public function quotaBytes(): ?int
    {
        return $this->has('quota_bytes') ? $this->integer('quota_bytes') : null;
    }

    /** The wanted "receives mail" switch; null when the body leaves it out. */
    public function receiving(): ?bool
    {
        return $this->has('receiving') ? $this->boolean('receiving') : null;
    }

    /** The wanted "send-only" switch; null when the body leaves it out. */
    public function sendonly(): ?bool
    {
        return $this->has('sendonly') ? $this->boolean('sendonly') : null;
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
