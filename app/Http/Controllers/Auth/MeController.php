<?php

namespace App\Http\Controllers\Auth;

use App\Auth\TwoFactorAccount;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTwoFactor;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    /**
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, two_factor_required: bool}
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(self::payload($this->user($request)));
    }

    /**
     * Change the signed-in admin's interface language. A preference, so it is not audited.
     *
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, two_factor_required: bool}
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate([
            'locale' => ['required', 'string', Rule::in(['en', 'fi'])],
        ]);

        $user->locale = $request->string('locale')->toString();
        $user->save();

        return response()->json(self::payload($user));
    }

    /** @return array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, two_factor_required: bool} */
    public static function payload(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'is_server_admin' => $user->is_server_admin,
            // The column only ever holds en or fi (PATCH /me validates it); anything else reads as en.
            'locale' => $user->locale === 'fi' ? 'fi' : 'en',
            'two_factor' => [
                'enabled' => $user->two_factor_secret !== null,
                'confirmed' => $user->hasEnabledTwoFactorAuthentication(),
                'recovery_codes_left' => self::recoveryCodesLeft($user),
            ],
            // Whether the setting asks this user for two-factor; until it is confirmed, RequireTwoFactor blocks them.
            'two_factor_required' => RequireTwoFactor::requiredFor($user),
        ];
    }

    /** Unused recovery codes; none count until two-factor is confirmed. */
    private static function recoveryCodesLeft(User $user): int
    {
        return $user->hasEnabledTwoFactorAuthentication() ? count(TwoFactorAccount::recoveryCodes($user)) : 0;
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(401);
        }

        return $user;
    }
}
