<?php

namespace App\Http\Requests;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\AuditActions;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * GET /audit-log's query: the filters (App\MailServer\Queries\AuditLog::paginate()), the paging
 * anchor and the page. The patterns end with `$` under the `D` modifier, so a trailing newline does
 * not match (TrimStrings strips one from a query string before validation anyway).
 */
class AuditLogRequest extends FormRequest
{
    private ?string $domain = null;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            /** A domain name in ASCII (punycode) or Unicode, any case: 422 when malformed, an empty page when unknown or not yours. */
            'domain' => ['nullable', 'string'],
            /** A user id, `cli` or `system` (GET /audit-log/actors lists them). */
            'actor' => ['nullable', 'string', 'regex:/^(cli|system|[1-9][0-9]{0,18})$/D'],
            'action_group' => ['nullable', 'string', Rule::in(array_keys(AuditActions::GROUPS))],
            /** Inclusive: an ISO 8601 date-time with an offset (`Z` or `±hh:mm`); seconds and fractions optional. */
            'from' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', 'date'],
            /** Exclusive: an ISO 8601 date-time with an offset, as `from`. */
            'to' => ['nullable', 'string', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d{1,6})?)?(Z|[+-]\d{2}:\d{2})$/D', 'date'],
            /**
             * The paging anchor: only rows with an id up to this one. Send the first page's newest id
             * with every later page, so rows written meanwhile don't shift the pages; drop it when the
             * filters change.
             */
            'before_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return array{0: callable(Validator): void} */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $domain = $this->query('domain');
            if ($validator->errors()->has('domain') || ! is_string($domain) || $domain === '') {
                return;
            }
            try {
                $this->domain = app(AddressNormalizer::class)->domain($domain)->ascii;
            } catch (InvalidAddress $e) {
                $validator->errors()->add('domain', $e->getMessage());
            }
        }];
    }

    /** @return array{domain: string|null, actor: string|null, action_group: string|null, from: Carbon|null, to: Carbon|null, before_id: int|null} */
    public function filters(): array
    {
        $beforeId = $this->text('before_id');

        return [
            'domain' => $this->domain,
            'actor' => $this->text('actor'),
            'action_group' => $this->text('action_group'),
            'from' => $this->instant('from'),
            'to' => $this->instant('to'),
            'before_id' => $beforeId === null ? null : (int) $beforeId,
        ];
    }

    private function text(string $key): ?string
    {
        $value = $this->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function instant(string $key): ?Carbon
    {
        $value = $this->text($key);

        return $value === null ? null : Carbon::parse($value);
    }
}
