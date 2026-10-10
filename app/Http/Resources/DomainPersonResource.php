<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One person with a grant on a domain (DomainPeople::list()).
 *
 * @property array{id: int, name: string, email: string, role: string, status: string, granted_by_name: string|null, granted_at: string|null} $resource
 */
class DomainPersonResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: int, name: string, email: string, role: 'manager'|'viewer', status: 'active'|'deactivated', granted_by_name: string|null, granted_at: string|null} */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var int */
            'id' => $r['id'],
            'name' => $r['name'],
            'email' => $r['email'],
            'role' => $r['role'] === 'manager' ? 'manager' : 'viewer',
            /** A deactivated user keeps the grant but has no role until reactivated. */
            'status' => $r['status'] === 'active' ? 'active' : 'deactivated',
            /** @var string|null Who gave the grant (their email when their name is empty); null when unknown or that user is gone */
            'granted_by_name' => $r['granted_by_name'],
            /** @var string|null When the grant was given (ISO 8601) */
            'granted_at' => $r['granted_at'],
        ];
    }
}
