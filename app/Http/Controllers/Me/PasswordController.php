<?php

namespace App\Http\Controllers\Me;

use App\Auth\AccountPassword;
use App\Http\Controllers\Controller;
use App\Http\Requests\SetupRequest;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** The signed-in user changes their own password. */
class PasswordController extends Controller
{
    /**
     * Needs the current password and follows the setup rules. Ends every other session of the user,
     * keeps this one signed in on a new session id, and emails a security notice. Wrong current
     * passwords count against the same limit as step-up.
     */
    public function update(Request $request, AccountPassword $account): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => SetupRequest::passwordRules(),
        ]);

        if (! Hash::check($request->string('current_password')->toString(), $user->password)) {
            // Same line as a failed sign-in (AuthFailureLogger); never the password.
            event(new Failed('web', $user, ['email' => $user->email]));

            throw ValidationException::withMessages(['current_password' => [trans()->string('validation.current_password')]]);
        }

        $account->change($user, $request->string('password')->toString(), $request->session());

        return response()->noContent();
    }
}
