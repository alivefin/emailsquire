<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property array{local: string, source: string, source_is_mailbox: bool, catch_all: bool, keep_copy: bool, targets: list<array{address: string, kind: string}>} $resource
 */
class ForwardingResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            'local' => $r['local'],
            'source' => $r['source'],
            /** @var bool */
            'source_is_mailbox' => $r['source_is_mailbox'],
            /** @var bool */
            'catch_all' => $r['catch_all'],
            /** @var bool */
            'keep_copy' => $r['keep_copy'],
            /** @var list<array{address: string, kind: 'mailbox'|'server'|'external'}> */
            'targets' => $r['targets'],
        ];
    }
}
