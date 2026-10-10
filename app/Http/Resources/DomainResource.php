<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property array{name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null} $resource */
class DomainResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null} */
    public function toArray(Request $request): array
    {
        return [
            'name' => $this->resource['name'],
            'unicode_name' => $this->resource['unicode_name'],
            /** @var int */
            'mailbox_count' => $this->resource['mailbox_count'],
            /** @var int */
            'forwarding_count' => $this->resource['forwarding_count'],
            /** @var int|null Sum of the domain's mailbox usage; null when no usage was read yet */
            'used_bytes' => $this->resource['used_bytes'],
        ];
    }
}
