<?php

namespace App\Http\Controllers\Me;

use App\Auth\TwoFactorAccount;
use App\Http\Controllers\Controller;
use App\Http\Problem;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;

/**
 * The signed-in user's own two-factor setup: enable (a new, unconfirmed secret), read the secret and
 * QR code while unconfirmed, confirm it with a code from the authenticator app, and turn it off.
 * Responses carrying the secret or the recovery codes are never cached.
 */
class TwoFactorController extends Controller
{
    /** Starts setup with a fresh secret and recovery codes. Needs step-up. Unconfirmed until `confirm`. */
    public function store(Request $request, EnableTwoFactorAuthentication $enable): Response|JsonResponse
    {
        $user = $this->user($request);
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->alreadyConfirmed();
        }

        // force: setting up again before confirming replaces the secret nobody has confirmed.
        $enable($user, true);

        return response()->noContent();
    }

    /**
     * The secret and its QR code (an SVG, base64-encoded for a `data:image/svg+xml;base64,` image),
     * only while two-factor is enabled and not yet confirmed. Needs step-up.
     *
     * @response array{qr_svg_base64: string, secret: string}
     */
    public function setup(Request $request): JsonResponse
    {
        $user = $this->user($request);
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->alreadyConfirmed();
        }
        if ($user->two_factor_secret === null) {
            return $this->notEnabled();
        }

        $secret = Fortify::currentEncrypter()->decrypt($user->two_factor_secret);

        return response()->json([
            'qr_svg_base64' => base64_encode($user->twoFactorQrCodeSvg()),
            'secret' => is_string($secret) ? $secret : '',
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Confirms the secret with a current code and answers the 8 recovery codes, shown this once.
     * Needs step-up.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function confirm(Request $request, TwoFactorAccount $account): JsonResponse
    {
        $user = $this->user($request);
        $request->validate([
            /** The 6-digit code the authenticator app shows for the new secret. */
            'code' => ['required', 'string'],
        ]);
        if ($user->hasEnabledTwoFactorAuthentication()) {
            return $this->alreadyConfirmed();
        }
        if ($user->two_factor_secret === null) {
            return $this->notEnabled();
        }

        try {
            if (! $account->confirm($user, $request->string('code')->toString())) {
                // Another request confirmed or removed the setup since this one loaded the user.
                return $user->two_factor_confirmed_at !== null ? $this->alreadyConfirmed() : $this->notEnabled();
            }
        } catch (ValidationException) {
            // Fortify's own message is English only and sits in an error bag the API doesn't use.
            throw ValidationException::withMessages(['code' => [trans()->string('emailsquire.two_factor.code_invalid')]]);
        }

        return response()->json(['recovery_codes' => TwoFactorAccount::recoveryCodes($user)])
            ->header('Cache-Control', 'no-store');
    }

    /** Turns two-factor off (or cancels an unconfirmed setup); the user gets a security notice. Needs step-up. */
    public function destroy(Request $request, TwoFactorAccount $account): Response
    {
        $account->disable($this->user($request));

        return response()->noContent();
    }

    private function alreadyConfirmed(): JsonResponse
    {
        return Problem::response(409, 'Conflict', trans()->string('emailsquire.two_factor.already_confirmed'), 'two_factor_already_confirmed');
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
