<?php

namespace App\Http\Controllers;

use App\Auth\Grants;
use App\Http\Requests\StoreUserGrantRequest;
use App\Http\Resources\UserGrantResource;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\UserList;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/** A user's grants from the Users screen (server admins). */
class UserGrantController extends Controller
{
    /**
     * Gives the user a role on a domain, or changes the role they have there, and returns the grant.
     *
     * 201 for a new grant, 200 for a changed or unchanged one. An unknown domain is a 422; 409
     * `already_has_access` when the user is a server admin, who has every domain.
     */
    public function store(StoreUserGrantRequest $request, User $user, Grants $grants, UserList $list): JsonResponse
    {
        $domain = $request->domain();
        $result = $grants->put($user, $domain, $request->role(), DomainInvitationController::actor($request));
        // Removed by a concurrent request since: there is no grant to show.
        $grant = $list->grants($user, $domain->id)[0] ?? abort(404);

        $resource = new UserGrantResource($grant);

        return $result === 'created' ? $resource->response()->setStatusCode(201) : $resource->response();
    }

    /**
     * Removes the user's role on a domain.
     *
     * Their pins of the domain go with it. 404 when they have no grant there.
     */
    public function destroy(User $user, Domain $domain, Grants $grants): Response
    {
        if (! $grants->remove($user, $domain)) {
            abort(404);
        }

        return response()->noContent();
    }
}
