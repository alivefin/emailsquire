<?php

namespace App\Auth;

use App\Models\User;

/** Which guide domains a console user may see. The one place phase 2's domain grants will plug in. */
class DomainAccess
{
    /** @return list<int>|null null means every domain (a server admin); phase 1 gives everyone else none */
    public function visibleDomainIds(User $user): ?array
    {
        return $user->is_server_admin ? null : [];
    }
}
