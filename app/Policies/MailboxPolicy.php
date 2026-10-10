<?php

namespace App\Policies;

use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Denials are 404s, so a non-admin cannot tell which mailboxes exist. */
class MailboxPolicy
{
    public function viewAny(User $user, Domain $domain): Response
    {
        return $this->admin($user);
    }

    public function view(User $user, Mailbox $mailbox): Response
    {
        return $this->admin($user);
    }

    public function create(User $user, Domain $domain): Response
    {
        return $this->admin($user);
    }

    public function update(User $user, Mailbox $mailbox): Response
    {
        return $this->admin($user);
    }

    public function delete(User $user, Mailbox $mailbox): Response
    {
        return $this->admin($user);
    }

    private function admin(User $user): Response
    {
        return $user->is_server_admin ? Response::allow() : Response::denyAsNotFound();
    }
}
