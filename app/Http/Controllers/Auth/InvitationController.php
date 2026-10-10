<?php

namespace App\Http\Controllers\Auth;

use App\Auth\Invitations;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Problem;
use App\Http\Requests\SetupRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The invitation link's page (SPA /invite#{token}): what the invitation offers, and accepting it. The
 * token is the only credential, so neither route needs a session. It travels only in POST bodies and
 * the link's fragment, never in a URL a server sees, so no access log records it. A missing, malformed
 * or unknown token answers 404 `invitation_not_found`, never a 422.
 */
class InvitationController extends Controller
{
    /**
     * What an invitation link offers, for its token holder.
     *
     * `existing_user` says whether the invited email already has an account (then accepting needs a
     * sign-in as that email). An invitation that is no longer valid is shown with its `state`; one
     * for the email of a deactivated user is `revoked`.
     *
     * @response array{email: string, name: string|null, inviter_name: string|null, domain: string|null, domain_unicode: string|null, role: 'server_admin'|'manager'|'viewer', note: string|null, state: 'valid'|'expired'|'used'|'revoked', existing_user: bool}
     */
    public function show(Request $request, Invitations $invitations): JsonResponse
    {
        $invitation = $invitations->byToken(self::token($request));
        if ($invitation === null) {
            return self::notFound();
        }
        $inviter = $invitation->inviter;

        return response()->json([
            'email' => $invitation->email,
            'name' => $invitation->name,
            'inviter_name' => $inviter === null ? null : Invitations::nameOf($inviter),
            'domain' => $invitation->domain_name,
            'domain_unicode' => $invitation->domain_name === null ? null : $invitations->unicode($invitation->domain_name),
            'role' => match ($invitation->role) {
                'server_admin' => 'server_admin',
                'manager' => 'manager',
                default => 'viewer',
            },
            'note' => $invitation->note,
            'state' => $invitations->linkState($invitation),
            'existing_user' => User::query()->where('email', $invitation->email)->exists(),
        ]);
    }

    /**
     * Accepts an invitation.
     *
     * The body always carries the `token`. A new person also sends `name`, `password` and `password_confirmation` (the setup rules: 12 or more
     * characters, not in a known breach when that check is on) and is signed in. An existing user
     * sends `{}` while signed in as the invited email (409 `sign_in_required` when signed out,
     * `wrong_account` when signed in as someone else); they are not signed in again. An invitation
     * that is no longer valid answers 410 `invitation_invalid` with its `state`.
     *
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, two_factor_required: bool, managers_can_share: bool, landing: string|null}
     */
    public function accept(Request $request, Invitations $invitations): JsonResponse
    {
        $invitation = $invitations->byToken(self::token($request));
        if ($invitation === null) {
            return self::notFound();
        }

        $signedIn = $this->signedIn($request);
        $newUser = null;
        if ($signedIn === null && ! User::query()->where('email', $invitation->email)->exists()) {
            $request->validate([
                /** The name shown in the console, 1–100 characters. */
                'name' => ['required', 'string', 'min:1', 'max:100'],
                'password' => SetupRequest::passwordRules(),
            ]);
            $newUser = ['name' => trim($request->string('name')->toString()), 'password' => $request->string('password')->toString()];
        }

        $user = $invitations->accept($invitation, $signedIn, $newUser);

        if ($signedIn === null) {
            // A new person: the link proved the email and they just chose the password. They have no
            // two-factor yet; RequireTwoFactor holds a new server admin at setup.
            Auth::guard()->login($user);
            $request->session()->regenerate();
        }

        return response()->json(MeController::payload($user));
    }

    /**
     * The signed-in user this browser stands for: active, and within the session's 12 hours (the
     * checks EnsureActiveUser and AbsoluteSessionLimit make on the signed-in routes).
     */
    private function signedIn(Request $request): ?User
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isActive()) {
            return null;
        }
        $signedInAt = $request->session()->get(AbsoluteSessionLimit::SESSION_KEY);
        if (is_int($signedInAt) && now()->getTimestamp() - $signedInAt > AbsoluteSessionLimit::MAX_AGE_SECONDS) {
            return null;
        }

        return $user;
    }

    /** The body's `token`; anything but a string reads as no token (a 404, not a 422). */
    private static function token(Request $request): string
    {
        $token = $request->input('token');

        return is_string($token) ? $token : '';
    }

    private static function notFound(): JsonResponse
    {
        return Problem::response(404, 'Not Found', trans()->string('emailsquire.invitation.not_found'), 'invitation_not_found');
    }
}
