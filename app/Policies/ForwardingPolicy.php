<?php

namespace App\Policies;

use App\Auth\DomainAccess;
use App\MailServer\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * As for mailboxes: reading needs a role on the domain, writing needs admin or manager. No role is a
 * 404, so nobody can tell which forwarding exists; a viewer's write is a 403.
 */
class ForwardingPolicy
{
    public function __construct(private readonly DomainAccess $access) {}

    public function viewAny(User $user, Domain $domain): Response
    {
        return $this->access->role($user, $domain) === null ? Response::denyAsNotFound() : Response::allow();
    }

    public function update(User $user, Domain $domain): Response
    {
        return $this->write($user, $domain);
    }

    public function delete(User $user, Domain $domain): Response
    {
        return $this->write($user, $domain);
    }

    private function write(User $user, Domain $domain): Response
    {
        return match (true) {
            $this->access->role($user, $domain) === null => Response::denyAsNotFound(),
            $this->access->canWrite($user, $domain) => Response::allow(),
            default => Response::deny(),
        };
    }
}
