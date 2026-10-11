<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AdminSetup;
use App\Auth\OtherSessions;
use App\Auth\RedirectIfTwoFactorAuthenticatable;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupRequest;
use App\MailServer\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Completes a one-time setup link from `emailsquire:create-admin`: sets the password and signs the user
 * in. A link from `--reset` can reach a user with a second factor (confirmed TOTP or a usable passkey);
 * the link proves only the email, so that user's sign-in is left pending, as after the password step
 * of POST /auth/login, and finishes with POST /auth/two-factor-challenge.
 */
class SetupController extends Controller
{
    /**
     * Sets the password from a setup link and signs the user in, or leaves the sign-in pending for the two-factor challenge.
     */
    public function __invoke(SetupRequest $request, AdminSetup $setup, AuditLog $audit): JsonResponse
    {
        $user = $setup->complete(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );

        // The 200 body is documented in ApiDocumentTransformer, as for POST /auth/login.
        // Any second factor, as at POST /auth/login: a passkey-only user doesn't skip the step either.
        if ($user->hasSecondFactor()) {
            $response = RedirectIfTwoFactorAuthenticatable::startPendingSignIn($request, $user);
        } else {
            Auth::guard()->login($user);
            $request->session()->regenerate();
            $response = response()->json(MeController::payload($user));
        }
        // A setup link from create-admin --reset changes a password: every other session ends.
        OtherSessions::end($user, $request->session()->getId());

        $audit->record('admin.setup', 'user', $user->email, actor: $user);

        return $response;
    }
}
