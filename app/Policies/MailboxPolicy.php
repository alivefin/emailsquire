<?php

namespace App\Policies;

use App\Auth\DomainAccess;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Reading needs a role on the domain, writing needs admin or manager (DomainAccess::canWrite). No role
 * is a 404, so nobody can tell which mailboxes exist; a viewer's write is a 403. A mailbox's domain is
 * its stored `domain_id`, never the URL.
 */
class MailboxPolicy
{
    public function __construct(private readonly DomainAccess $access) {}

    public function viewAny(User $user, Domain $domain): Response
    {
        return $this->read($this->access->role($user, $domain));
    }

    public function view(User $user, Mailbox $mailbox): Response
    {
        return $this->read($this->access->roleForId($user, $mailbox->domain_id));
    }

    public function create(User $user, Domain $domain): Response
    {
        return $this->write($this->access->role($user, $domain));
    }

    public function update(User $user, Mailbox $mailbox): Response
    {
        return $this->write($this->access->roleForId($user, $mailbox->domain_id));
    }

    public function delete(User $user, Mailbox $mailbox): Response
    {
        return $this->write($this->access->roleForId($user, $mailbox->domain_id));
    }

    public function bulkUpdate(User $user, Domain $domain): Response
    {
        return $this->write($this->access->role($user, $domain));
    }

    private function read(?string $role): Response
    {
        return $role === null ? Response::denyAsNotFound() : Response::allow();
    }

    private function write(?string $role): Response
    {
        return match ($role) {
            null => Response::denyAsNotFound(),
            'admin', 'manager' => Response::allow(),
            default => Response::deny(),
        };
    }
}
