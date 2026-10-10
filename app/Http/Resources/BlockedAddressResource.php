<?php

namespace App\Http\Resources;

use App\Models\BlockedAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @property BlockedAddress $resource */
class BlockedAddressResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{address: string, reason: string|null, created_at: string|null, created_by_name: string|null} */
    public function toArray(Request $request): array
    {
        $b = $this->resource;

        return [
            'address' => $b->address,
            'reason' => $b->reason,
            'created_at' => $b->created_at?->toIso8601String(),
            'created_by_name' => $b->creator?->name,
        ];
    }
}
