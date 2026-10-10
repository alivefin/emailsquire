<?php

namespace App\Policies;

use App\Auth\DomainAccess;
use App\Auth\Grants;
use App\MailServer\Models\Domain;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * A domain the user has no role on answers 404, like one that does not exist, so nobody can probe
 * which domains the server hosts. A role that lacks the permission answers 403.
 */
class DomainPolicy
{
    public function __construct(
        private readonly DomainAccess $access,
        private readonly Grants $grants,
    ) {}

    /** Every signed-in user; the list holds only their domains (DomainScope). */
    public function viewAny(User $user): Response
    {
        return Response::allow();
    }

    public function view(User $user, Domain $domain): Response
    {
        return $this->access->role($user, $domain) === null ? Response::denyAsNotFound() : Response::allow();
    }

    public function create(User $user): Response
    {
        return $this->access->isServerAdmin($user) ? Response::allow() : Response::deny();
    }

    public function delete(User $user, Domain $domain): Response
    {
        return $this->adminOnly($user, $domain);
    }

    /** Grants and invitations: admins, and managers while `managers_can_share` is on. */
    public function share(User $user, Domain $domain): Response
    {
        return match (true) {
            $this->access->role($user, $domain) === null => Response::denyAsNotFound(),
            $this->access->canShare($user, $domain) => Response::allow(),
            default => Response::deny(),
        };
    }

    /**
     * Changing a grantee's role: `share`. A target without a grant on this domain (a server admin
     * included) is a 404, the same as an id nobody has, and comes before step-up.
     */
    public function changeAccess(User $user, Domain $domain, User $target): Response
    {
        return match (true) {
            $this->access->role($user, $domain) === null, $this->grants->find($target, $domain) === null => Response::denyAsNotFound(),
            $this->access->canShare($user, $domain) => Response::allow(),
            default => Response::deny(),
        };
    }

    /** Removing a grant: `share`, or anyone with a role removing their own. A target without a grant is a 404. */
    public function removeAccess(User $user, Domain $domain, User $target): Response
    {
        return match (true) {
            $this->access->role($user, $domain) === null, $this->grants->find($target, $domain) === null => Response::denyAsNotFound(),
            $user->is($target), $this->access->canShare($user, $domain) => Response::allow(),
            default => Response::deny(),
        };
    }

    public function viewLimits(User $user, Domain $domain): Response
    {
        return $this->view($user, $domain);
    }

    public function updateLimits(User $user, Domain $domain): Response
    {
        return $this->adminOnly($user, $domain);
    }

    private function adminOnly(User $user, Domain $domain): Response
    {
        return match ($this->access->role($user, $domain)) {
            null => Response::denyAsNotFound(),
            'admin' => Response::allow(),
            default => Response::deny(),
        };
    }
}
