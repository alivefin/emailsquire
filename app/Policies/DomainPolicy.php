<?php

namespace App\Policies;

use App\MailServer\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Denials are 404s, so a non-admin cannot tell which domains exist. */
class DomainPolicy
{
    public function viewAny(User $user): Response
    {
        return $this->admin($user);
    }

    public function view(User $user, Domain $domain): Response
    {
        return $this->admin($user);
    }

    public function create(User $user): Response
    {
        return $this->admin($user);
    }

    public function delete(User $user, Domain $domain): Response
    {
        return $this->admin($user);
    }

    private function admin(User $user): Response
    {
        return $user->is_server_admin ? Response::allow() : Response::denyAsNotFound();
    }
}
