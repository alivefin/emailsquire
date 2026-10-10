<?php

namespace App\Http\Resources;

use App\MailServer\Queries\UserList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A console user as the Users screen lists them (UserList).
 *
 * @phpstan-import-type Row from UserList
 *
 * @property Row $resource
 */
class UserResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{id: int, name: string, email: string, is_server_admin: bool, status: 'active'|'deactivated', two_factor_confirmed: bool, manager_of: int, viewer_of: int, last_login_at: string|null} */
    public function toArray(Request $request): array
    {
        return self::fields($this->resource);
    }

    /**
     * @param  Row  $r
     * @return array{id: int, name: string, email: string, is_server_admin: bool, status: 'active'|'deactivated', two_factor_confirmed: bool, manager_of: int, viewer_of: int, last_login_at: string|null}
     */
    public static function fields(array $r): array
    {
        return [
            /** @var int */
            'id' => $r['id'],
            'name' => $r['name'],
            'email' => $r['email'],
            /** @var bool A server admin has every domain and the Users screen */
            'is_server_admin' => $r['is_server_admin'],
            /** A deactivated user can't sign in and has no role anywhere until reactivated */
            'status' => $r['status'] === 'active' ? 'active' : 'deactivated',
            /** @var bool Whether two-factor authentication is set up and confirmed */
            'two_factor_confirmed' => $r['two_factor_confirmed'],
            /** @var int How many domains they manage; 0 for a server admin, who has every domain */
            'manager_of' => $r['manager_of'],
            /** @var int How many domains they view; 0 for a server admin */
            'viewer_of' => $r['viewer_of'],
            /** @var string|null Their last sign-in (ISO 8601); null when they never signed in */
            'last_login_at' => $r['last_login_at'],
        ];
    }
}
