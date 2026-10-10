<?php

namespace App\Http\Resources;

use App\Auth\Invitations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation on a domain's Access tab that is neither accepted nor revoked (Invitations::pendingFor()).
 *
 * @phpstan-import-type PendingInvitation from Invitations
 *
 * @property PendingInvitation $resource
 */
class PendingInvitationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: int, email: string, role: 'manager'|'viewer', invited_by_name: string|null, created_at: string|null, expires_at: string, state: 'valid'|'expired'|'revoked'} */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var int */
            'id' => $r['id'],
            'email' => $r['email'],
            'role' => $r['role'] === 'manager' ? 'manager' : 'viewer',
            /** @var string|null Who sent it (their email when their name is empty); null when that user is gone */
            'invited_by_name' => $r['invited_by_name'],
            /** @var string|null When it was sent (ISO 8601) */
            'created_at' => $r['created_at'],
            /** @var string When the link stops working (ISO 8601) */
            'expires_at' => $r['expires_at'],
            /** `valid`, `expired`, or `revoked` when its inviter can no longer share the domain */
            'state' => match ($r['state']) {
                'valid' => 'valid',
                'expired' => 'expired',
                default => 'revoked',
            },
        ];
    }
}
