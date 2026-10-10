<?php

namespace App\Http\Controllers\Auth;

use App\Auth\AccountPassword;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * "Sign out everywhere": ends every other session of the signed-in user and keeps this one. Audited
 * with the number of sessions ended; the user gets a security notice.
 */
class LogoutAllController extends Controller
{
    public function __invoke(Request $request, AccountPassword $account): Response
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        $account->signOutOthers($user, $request->session()->getId());

        return response()->noContent();
    }
}
