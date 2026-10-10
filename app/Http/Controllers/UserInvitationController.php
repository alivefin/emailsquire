<?php

namespace App\Http\Controllers;

use App\Auth\Invitations;
use App\Http\Requests\StoreUserInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Invitation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/** Invitations from the Users screen (server admins): to be a server admin, or to any domain. */
class UserInvitationController extends Controller
{
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
     * one whose domain is gone, answers 409 `invitation_closed`.
     */
    public function resend(Request $request, Invitation $invitation, Invitations $invitations): InvitationResource
    {
        $invitations->resend($invitation, DomainInvitationController::actor($request));

        return new InvitationResource($invitation);
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
