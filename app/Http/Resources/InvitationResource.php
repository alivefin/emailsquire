<?php

namespace App\Http\Resources;

use App\Auth\Invitations;
use App\Models\Invitation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation as its creator sees it after creating or resending it.
 *
 * @property Invitation $resource
 */
class InvitationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: int, email: string, role: 'server_admin'|'manager'|'viewer', domain: string|null, state: 'valid'|'expired'|'used'|'revoked', expires_at: string} */
    public function toArray(Request $request): array
    {
        $i = $this->resource;

        return [
            'id' => $i->id,
            'email' => $i->email,
            'role' => match ($i->role) {
                'server_admin' => 'server_admin',
                'manager' => 'manager',
                default => 'viewer',
            },
            /** The domain's ASCII name; null for a server-admin invitation */
            'domain' => $i->domain_name,
            'state' => app(Invitations::class)->state($i),
            /** When the link stops working (ISO 8601) */
            'expires_at' => $i->expires_at->toIso8601String(),
        ];
    }
}
