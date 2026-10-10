<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkMailboxRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'locals' => ['required', 'array', 'min:1', 'max:200'],
            'locals.*' => ['required', 'string', 'max:100'],
            'action' => ['required', 'string', 'in:quota,disable,enable'],
            'quota_bytes' => ['required_if:action,quota', 'nullable', 'integer', 'min:'.StoreMailboxRequest::MIN_QUOTA, 'max:'.StoreMailboxRequest::MAX_QUOTA],
        ];
    }

    /** @return list<string> */
    public function locals(): array
    {
        return array_values(array_filter((array) $this->input('locals'), is_string(...)));
    }

    public function action(): string
    {
        return $this->string('action')->value();
    }

    public function quotaBytes(): ?int
    {
        return $this->action() === 'quota' ? $this->integer('quota_bytes') : null;
    }
}
