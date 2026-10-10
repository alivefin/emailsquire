<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AccountPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Sets a new password with a forgotten-password link (30 minutes, once). It ends every session of
 * that user and signs nobody in, this browser included: the user then signs in as usual, with their
 * two-factor code if they have one. Same password rules as setup.
 */
class ResetPasswordController extends Controller
{
    /**
     * @response array{reset: true}
     */
    public function __invoke(SetupRequest $request, AccountPassword $account): JsonResponse
    {
        $user = $account->reset(
            $request->string('email')->toString(),
            $request->string('token')->toString(),
            $request->string('password')->toString(),
        );
        if ($user === null) {
            // One message for a wrong, spent or expired token, and for an unknown email.
            throw ValidationException::withMessages(['token' => [trans()->string('emailsquire.reset.invalid_link')]]);
        }

        // Nothing signed in survives a reset in this browser either, not even a half-finished
        // two-factor sign-in (`login.id`).
        if (Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
        }
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['reset' => true]);
    }
}
