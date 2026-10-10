<?php

namespace App\Policies;

use App\Auth\DomainAccess;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Console users (the Users screen): server admins only. Anyone else gets 404, as if the screen and
 * the user did not exist.
 */
class UserPolicy
{
    public function __construct(private readonly DomainAccess $access) {}

    public function viewAny(User $user): Response
    {
        return $this->admin($user);
    }

    public function view(User $user, User $model): Response
    {
        return $this->admin($user);
    }

    public function create(User $user): Response
    {
        return $this->admin($user);
    }

    public function update(User $user, User $model): Response
    {
        return $this->admin($user);
    }

    public function delete(User $user, User $model): Response
    {
        return $this->admin($user);
    }

    private function admin(User $user): Response
    {
        return $this->access->isServerAdmin($user) ? Response::allow() : Response::denyAsNotFound();
    }
}
