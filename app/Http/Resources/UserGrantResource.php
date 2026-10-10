<?php

namespace App\Http\Resources;

use App\MailServer\Queries\UserList;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One of a user's grants on the Users screen (UserList::grants()).
 *
 * @phpstan-import-type Grant from UserList
 *
 * @property Grant $resource
 */
class UserGrantResource extends JsonResource
{
    public static $wrap = null;

    /** @return array{domain: string, domain_unicode: string, role: 'manager'|'viewer', granted_by_name: string|null, granted_at: string|null} */
    public function toArray(Request $request): array
    {
        $r = $this->resource;

        return [
            /** The domain's ASCII name */
            'domain' => $r['domain'],
            /** The domain's unicode name, for display */
            'domain_unicode' => $r['domain_unicode'],
            'role' => $r['role'] === 'manager' ? 'manager' : 'viewer',
            /** @var string|null Who gave the grant (their email when their name is empty); null when unknown or that user is gone */
            'granted_by_name' => $r['granted_by_name'],
            /** @var string|null When the grant was given (ISO 8601) */
            'granted_at' => $r['granted_at'],
        ];
    }
}
