<?php

namespace App\Http\Resources;

use App\Auth\Invitations;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An invitation on the Users screen's pending list, to the server or to any domain, that is neither
 * accepted nor revoked (Invitations::pendingAll()).
 *
 * @phpstan-import-type ListedInvitation from Invitations
 *
 * @property ListedInvitation $resource
 */
class ListedInvitationResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: int, email: string, role: 'server_admin'|'manager'|'viewer', domain: string|null, domain_unicode: string|null, invited_by_name: string|null, created_at: string|null, expires_at: string, state: 'valid'|'expired'|'revoked'} */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** @var int */
            'id' => $r['id'],
            /** The invited email */
            'email' => $r['email'],
            'role' => match ($r['role']) {
                'server_admin' => 'server_admin',
                'manager' => 'manager',
                default => 'viewer',
            },
            /** @var string|null The domain's ASCII name; null for a server-admin invitation */
            'domain' => $r['domain'],
            /** @var string|null The domain's unicode name; null for a server-admin invitation */
            'domain_unicode' => $r['domain_unicode'],
            /** @var string|null Who sent it (their email when their name is empty); null when that user is gone */
            'invited_by_name' => $r['invited_by_name'],
            /** @var string|null When it was sent (ISO 8601) */
            'created_at' => $r['created_at'],
            /** @var string When the link stops working (ISO 8601) */
            'expires_at' => $r['expires_at'],
            /** `valid`, `expired`, or `revoked` when its domain is gone or its inviter can no longer send it */
            'state' => match ($r['state']) {
                'valid' => 'valid',
                'expired' => 'expired',
                default => 'revoked',
            },
        ];
    }
}
