<?php

namespace App\Http\Controllers;

use App\Auth\Invitations;
use App\Http\Requests\StoreDomainInvitationRequest;
use App\Http\Resources\InvitationResource;
use App\MailServer\Models\Domain;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Invitations to a domain from its Access tab: admins, and managers while `managers_can_share` is on.
 * An invitation of another domain (or of the server) answers 404 from the policy, before step-up.
 */
class DomainInvitationController extends Controller
{
    /**
     * Invites someone by email to a role on the domain and emails them a link valid for 72 hours.
     *
     * 409 `already_has_access` when the email already has access to the domain (a server admin
     * included) or a pending invitation to it; 409 `user_deactivated` when it belongs to a
     * deactivated user.
     */
    public function store(StoreDomainInvitationRequest $request, Domain $domain, Invitations $invitations): JsonResponse
    {
        $invitation = $invitations->create(
            $request->email(),
            $request->domainRole(),
            $domain,
            $request->note(),
            $request->mailLocale(),
            $request->name(),
            self::actor($request),
        );

        return (new InvitationResource($invitation))->response()->setStatusCode(201);
    }

    /**
     * Sends the invitation again with a new link, valid for 72 hours from now; the old link stops working.
     *
     * The invitation is sent in the name of whoever resends it. An accepted or revoked invitation
     * answers 409 `invitation_closed`.
     */
    public function resend(Request $request, Domain $domain, Invitation $invitation, Invitations $invitations): InvitationResource
    {
        $invitations->resend($invitation, self::actor($request));

        return new InvitationResource($invitation);
    }

    /**
     * Revokes the invitation; its link stops working.
     *
     * An accepted or already revoked invitation answers 409 `invitation_closed`.
     */
    public function destroy(Request $request, Domain $domain, Invitation $invitation, Invitations $invitations): Response
    {
        $invitations->revoke($invitation, self::actor($request));

        return response()->noContent();
    }

    public static function actor(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
