<?php

namespace App\Http\Controllers;

use App\Auth\ConsoleUsers;
use App\Auth\Invitations;
use App\Http\Middleware\RequireStepUp;
use App\Http\Requests\UpdateUserRequest;
use App\Http\Resources\UserDetailResource;
use App\Http\Resources\UserResource;
use App\MailServer\Queries\UserList;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** The Users screen: every console user, for server admins only (anyone else gets 404). */
class UserController extends Controller
{
    /**
     * Every console user, sorted by name (the email when the name is empty), with how many domains they manage and view.
     *
     * `q` matches part of the name or email.
     *
     * @response array{data: list<\App\Http\Resources\UserResource>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request, UserList $list): JsonResponse
    {
        $q = $request->query('q');
        $page = max(1, $request->integer('page', 1));
        /** How many users a page holds: 10 by default, at most 200. `limit` is accepted too, as on the other lists. */
        $perPage = $request->integer('per_page', $request->integer('limit', 10));
        $result = $list->paginate(is_string($q) ? trim($q) : null, $page, min(200, max(1, $perPage)));

        return response()->json([
            'data' => UserResource::collection($result->items())->resolve($request),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    /**
     * A console user with their grants and the pending invitations to their email.
     *
     * A server admin has every domain, so lists no grants.
     */
    public function show(User $user, UserList $list, Invitations $invitations): UserDetailResource
    {
        return new UserDetailResource([
            'user' => $list->find($user),
            'grants' => $list->grants($user),
            'invitations' => $invitations->pendingForEmail($user->email),
        ]);
    }

    /**
     * Changes a user's name, email, server-admin role or status; at least one of them.
     *
     * A name alone needs no step-up; an email, role or status does (423 `step_up_required`, checked
     * after validation: an invalid body answers 422 first). A field that keeps its value changes
     * nothing. A new email tells the old address and revokes pending invitations to it. Making someone
     * a server admin removes their grants; changing the role or deactivating signs them out everywhere. 409
     * `cannot_change_self` when you demote or deactivate yourself; 409 `last_server_admin` when it
     * would leave no active server admin.
     */
    public function update(UpdateUserRequest $request, User $user, ConsoleUsers $users, UserList $list): UserResource
    {
        if ($request->needsStepUp()) {
            RequireStepUp::ensure($request);
        }

        return new UserResource($list->find($users->update($user, $request->changes(), DomainInvitationController::actor($request))));
    }

    /**
     * Deletes a user.
     *
     * Their grants and sessions go with them; invitations they sent, and pending invitations to their
     * email, stop working. 409
     * `cannot_change_self` for yourself; 409 `last_server_admin` for the last active server admin.
     */
    public function destroy(Request $request, User $user, ConsoleUsers $users): Response
    {
        $users->delete($user, DomainInvitationController::actor($request));

        return response()->noContent();
    }

    /**
     * Turns off a user's two-factor authentication and removes their passkeys, for someone who lost them and the recovery codes.
     *
     * They are emailed, and set it up again at their next sign-in. 409 `cannot_change_self` for
     * yourself; 409 `two_factor_not_enabled` when they have neither confirmed two-factor nor a passkey.
     */
    public function resetTwoFactor(Request $request, User $user, ConsoleUsers $users): Response
    {
        $users->resetTwoFactor($user, DomainInvitationController::actor($request));

        return response()->noContent();
    }
}
