<?php

namespace App\Http\Controllers;

use App\Auth\Grants;
use App\Http\Requests\UpdateDomainAccessRequest;
use App\Http\Resources\DomainPersonResource;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\DomainPeople;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Who has a role on a domain: the people with a grant, and the pending invitations (plan 2c, Task 4). */
class DomainAccessController extends Controller
{
    /**
     * The people with a grant on the domain, sorted by name.
     *
     * A person with an empty name is sorted by their email. Server admins have every domain and are
     * not listed, even with a grant left from before they became one.
     *
     * @response array{people: list<\App\Http\Resources\DomainPersonResource>, invitations: list<mixed>}
     */
    public function index(Request $request, Domain $domain, DomainPeople $people): JsonResponse
    {
        return response()->json([
            'people' => DomainPersonResource::collection($people->list($domain))->resolve($request),
            'invitations' => [],
        ]);
    }

    /**
     * Changes a grantee's role and returns the person.
     *
     * The same role again changes nothing. A user without a grant on the domain is a 404.
     */
    public function update(UpdateDomainAccessRequest $request, Domain $domain, User $user, Grants $grants): DomainPersonResource
    {
        return new DomainPersonResource($grants->change($user, $domain, $request->role()));
    }

    /**
     * Removes a grantee's access.
     *
     * Their pins of the domain go with it. Anyone with a role may remove their own; removing someone
     * else's needs sharing. A user without a grant on the domain is a 404.
     */
    public function destroy(Domain $domain, User $user, Grants $grants): Response
    {
        if (! $grants->remove($user, $domain)) {
            abort(404);
        }

        return response()->noContent();
    }
}
