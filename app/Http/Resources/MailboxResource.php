<?php

namespace App\Http\Resources;

use App\MailServer\QuotaFormat;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{local: string, address: string, display_name: string|null, quota: int, status: string, used_bytes: int|null, usage_checked_at: string|null, forwarding_count: int} $resource
 */
class MailboxResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'local' => $r['local'],
            'address' => $r['address'],
            /** @var string|null */
            'display_name' => $r['display_name'],
            'quota_bytes' => QuotaFormat::effectiveBytes($r['quota']),
            'quota_is_default' => $r['quota'] === 0,
            /** @var 'active'|'disabled' */
            'status' => $r['status'],
            /** @var int|null */
            'used_bytes' => $r['used_bytes'],
            /** @var string|null ISO 8601, or null when usage was never read */
            'usage_checked_at' => $r['usage_checked_at'],
            /** @var int */
            'forwarding_count' => $r['forwarding_count'],
        ];
    }
}
