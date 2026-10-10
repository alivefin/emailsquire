<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDomainSettingsRequest extends FormRequest
{
    public const MAX_MAILBOXES = 100000;

    /** 1 PiB. */
    public const MAX_QUOTA_TOTAL = 1125899906842624;

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            // null is unlimited.
            'max_mailboxes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.self::MAX_MAILBOXES],
            'max_quota_bytes_total' => ['sometimes', 'nullable', 'integer', 'min:'.StoreMailboxRequest::MIN_QUOTA, 'max:'.self::MAX_QUOTA_TOTAL],
        ];
    }

    /** @return array{max_mailboxes?: ?int, max_quota_bytes_total?: ?int} */
    public function limits(): array
    {
        $values = [];
        foreach (['max_mailboxes', 'max_quota_bytes_total'] as $field) {
            if ($this->has($field)) {
                $values[$field] = $this->input($field) === null ? null : $this->integer($field);
            }
        }

        return $values;
    }
}
