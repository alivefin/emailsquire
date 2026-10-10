<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Denials are 404s, as for domains. */
class BlockedAddressPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->admin($user);
    }

    public function create(User $user): Response
    {
        return $this->admin($user);
    }

    public function delete(User $user): Response
    {
        return $this->admin($user);
    }

    private function admin(User $user): Response
    {
        return $user->is_server_admin ? Response::allow() : Response::denyAsNotFound();
    }
}
