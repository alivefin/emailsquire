<?php

namespace App\Http\Resources;

use App\Auth\DomainAccess;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use LogicException;

/** @property array{id: int, name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null} $resource */
class DomainResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{name: string, unicode_name: string, mailbox_count: int, forwarding_count: int, used_bytes: int|null, role: 'admin'|'manager'|'viewer'} */
    public function toArray(Request $request): array
    {
        $user = $request->user();
        $role = $user instanceof User ? app(DomainAccess::class)->roleForId($user, $this->resource['id']) : null;

        return [
            'name' => $this->resource['name'],
            'unicode_name' => $this->resource['unicode_name'],
            /** @var int */
            'mailbox_count' => $this->resource['mailbox_count'],
            /** @var int */
            'forwarding_count' => $this->resource['forwarding_count'],
            /** @var int|null Sum of the domain's mailbox usage; null when no usage was read yet */
            'used_bytes' => $this->resource['used_bytes'],
            /**
             * The requester's role on this domain: `admin` (a server admin), `manager` or `viewer`.
             *
             * @var 'admin'|'manager'|'viewer'
             */
            'role' => $role ?? throw new LogicException('A listed domain has no role.'),
        ];
    }
}
