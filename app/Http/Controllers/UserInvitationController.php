<?php

namespace App\Http\Controllers;

use App\Auth\Invitations;
use App\Http\Requests\StoreUserInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Http\Resources\ListedInvitationResource;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Invitations from the Users screen (server admins): to be a server admin, or to any domain. */
class UserInvitationController extends Controller
{
    /**
     * Every invitation that is neither accepted nor revoked, to the server or to any domain, newest first.
     *
     * An expired invitation is listed until it is revoked; `state` is `revoked` for one whose domain is
     * gone or whose inviter can no longer send it.
     *
     * @response array{data: list<\App\Http\Resources\ListedInvitationResource>, meta: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function index(Request $request, Invitations $invitations): JsonResponse
    {
        $page = max(1, $request->integer('page', 1));
        /** How many invitations a page holds: 10 by default, at most 200. */
        $perPage = $request->integer('per_page', 10);
        $result = $invitations->pendingAll($page, min(200, max(1, $perPage)));

        return response()->json([
            'data' => ListedInvitationResource::collection($result->items())->resolve($request),
            'meta' => [
                'page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
            ],
        ]);
    }

    /**
     * Invites someone by email to be a server admin, or to a role on a domain, and emails them a link valid for 72 hours.
     *
     * A `manager` or `viewer` needs a `domain`; a `server_admin` takes none. 409 `already_has_access`
     * when the email already has that access or a pending invitation to it; 409 `user_deactivated`
     * when it belongs to a deactivated user.
     */
    public function store(StoreUserInvitationRequest $request, Invitations $invitations): JsonResponse
    {
        $invitation = $invitations->create(
            $request->email(),
            $request->role(),
            $request->domain(),
            $request->note(),
            $request->mailLocale(),
            $request->name(),
            DomainInvitationController::actor($request),
        );

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    /**
     * Sends any invitation again with a new link, valid for 72 hours from now; the old link stops working.
     *
     * The invitation is sent in the name of whoever resends it. An accepted or revoked invitation, or
     * one whose domain is gone, answers 409 `invitation_closed`; one that creating would now refuse
     * answers the same 409.
     */
    public function resend(Request $request, Invitation $invitation, Invitations $invitations): InvitationResource
    {
        return new InvitationResource($invitations->resend($invitation, DomainInvitationController::actor($request)));
    }

    /**
     * Revokes any invitation; its link stops working.
     *
     * An accepted or already revoked invitation answers 409 `invitation_closed`.
     */
    public function destroy(Request $request, Invitation $invitation, Invitations $invitations): Response
    {
        $invitations->revoke($invitation, DomainInvitationController::actor($request));

        return response()->noContent();
    }
}
