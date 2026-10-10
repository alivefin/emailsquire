<?php

namespace App\Http\Controllers\Me;

use App\Auth\TwoFactorAccount;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** The signed-in user's unused recovery codes. Both routes need step-up and are never cached. */
class RecoveryCodesController extends Controller
{
    /**
     * The codes not yet used, each good for one sign-in.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->notEnabled();
        }

        return response()->json(['recovery_codes' => TwoFactorAccount::recoveryCodes($user)])->header('Cache-Control', 'no-store');
    }

    /**
     * Replaces every code with 8 new ones; the old ones stop working.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function store(Request $request, TwoFactorAccount $account): JsonResponse
    {
        $user = $this->user($request);
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->notEnabled();
        }

        $account->regenerateRecoveryCodes($user);

        return response()->json(['recovery_codes' => TwoFactorAccount::recoveryCodes($user)])->header('Cache-Control', 'no-store');
    }

    private function notEnabled(): JsonResponse
    {
        return Problem::response(404, 'Not Found', trans()->string('emailsquire.two_factor.not_enabled'), 'two_factor_not_enabled');
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : abort(401);
    }
}
