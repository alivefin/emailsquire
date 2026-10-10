<?php

namespace App\Policies;

use App\Auth\DomainAccess;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Every signed-in user may list blocked addresses (only those on their own domains: the repository
 * scopes the list); only server admins add or remove them. The routes take no domain, so a refusal is
 * a 403 for everyone else and says nothing about which addresses exist.
 */
class BlockedAddressPolicy
{
    public function __construct(private readonly DomainAccess $access) {}

    public function viewAny(User $user): Response
    {
        return Response::allow();
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
        return $this->access->isServerAdmin($user) ? Response::allow() : Response::deny();
    }
}
