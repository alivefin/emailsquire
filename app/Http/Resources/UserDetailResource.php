<?php

namespace App\Http\Resources;

use App\Auth\Invitations;
use App\MailServer\Queries\UserList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A console user with their grants and the pending invitations to their email (GET /users/{user}).
 *
 * @phpstan-import-type Row from UserList
 * @phpstan-import-type Grant from UserList
 * @phpstan-import-type EmailInvitation from Invitations
 *
 * @property array{user: Row, grants: list<Grant>, invitations: list<EmailInvitation>} $resource
 */
class UserDetailResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: int, name: string, email: string, is_server_admin: bool, status: 'active'|'deactivated', two_factor_confirmed: bool, second_factor: bool, manager_of: int, viewer_of: int, last_login_at: string|null, grants: list<array<string, mixed>>, invitations: list<array<string, mixed>>} */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            ...UserResource::fields($r['user']),
            /** @var list<UserGrantResource> Their grants, by domain name; none for a server admin, who has every domain */
            'grants' => array_map(fn (array $g): array => (new UserGrantResource($g))->toArray($request), $r['grants']),
            /** @var list<UserInvitationResource> Invitations to their email that are neither accepted nor revoked, newest first */
            'invitations' => array_map(fn (array $i): array => (new UserInvitationResource($i))->toArray($request), $r['invitations']),
        ];
    }
}
