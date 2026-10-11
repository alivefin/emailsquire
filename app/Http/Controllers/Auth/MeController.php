<?php

namespace App\Http\Controllers\Auth;

use App\Auth\DomainAccess;
use App\Auth\TwoFactorAccount;
use App\Http\Controllers\Controller;
use App\Http\Middleware\RequireTwoFactor;
use App\MailServer\CatchAllSupport;
use App\MailServer\Models\Domain;
use App\MailServer\SchemaExtension;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MeController extends Controller
{
    /**
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, second_factor: bool, passkeys: int, two_factor_required: bool, managers_can_share: bool, landing: string|null, capabilities: array{schema_extension: array{receiving: bool, sendonly: bool}, catch_all: bool}}
     */
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(self::payload($this->user($request)));
    }

    /**
     * Change the signed-in user's display name, interface language or both; at least one of them.
     * Preferences, so neither is audited.
     *
     * @response array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, second_factor: bool, passkeys: int, two_factor_required: bool, managers_can_share: bool, landing: string|null, capabilities: array{schema_extension: array{receiving: bool, sendonly: bool}, catch_all: bool}}
     */
    public function update(Request $request): JsonResponse
    {
        $user = $this->user($request);
        $request->validate([
            /** The name shown in the console, 1–100 characters. */
            'name' => ['required_without:locale', 'string', 'min:1', 'max:100'],
            'locale' => ['required_without:name', 'string', Rule::in(['en', 'fi'])],
        ]);

        if ($request->has('name')) {
            $user->name = $request->string('name')->toString();
        }
        if ($request->has('locale')) {
            $user->locale = $request->string('locale')->toString();
        }
        $user->save();

        return response()->json(self::payload($user));
    }

    /** @return array{id: int, name: string, email: string, is_server_admin: bool, locale: 'en'|'fi', two_factor: array{enabled: bool, confirmed: bool, recovery_codes_left: int}, second_factor: bool, passkeys: int, two_factor_required: bool, managers_can_share: bool, landing: string|null, capabilities: array{schema_extension: array{receiving: bool, sendonly: bool}, catch_all: bool}} */
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
            // Confirmed TOTP or a usable passkey: what required two-factor asks for (User::hasSecondFactor()).
            'second_factor' => $user->hasSecondFactor(),
            // Passkeys that can sign in and confirm a step-up (disabled ones aren't counted).
            'passkeys' => $user->usablePasskeys()->count(),
            // Whether the setting asks this user for two-factor; until it is confirmed, RequireTwoFactor blocks them.
            'two_factor_required' => RequireTwoFactor::requiredFor($user),
            // Whether this server lets a domain's managers share it; the console words its sharing controls by it.
            'managers_can_share' => config()->boolean('emailsquire.managers_can_share'),
            // Where the console opens: the user's only domain when they are not an admin and have exactly one.
            'landing' => self::landing($user),
            // What this server supports, so the console shows only the controls that would work.
            'capabilities' => [
                'schema_extension' => app(SchemaExtension::class)->capabilities(),
                'catch_all' => app(CatchAllSupport::class)->supported(),
            ],
        ];
    }

    /** The ASCII name of a non-admin's only domain; null for an admin or with no or several domains. */
    private static function landing(User $user): ?string
    {
        if (app(DomainAccess::class)->scope($user)->isAll()) {
            return null;
        }

        $names = Domain::query()->visibleTo($user)->orderBy('name')->limit(2)->pluck('name');

        return $names->count() === 1 && is_string($names->first()) ? $names->first() : null;
    }

    /** Unused recovery codes; none count until there is a second factor (confirmed TOTP or a passkey). */
    private static function recoveryCodesLeft(User $user): int
    {
        return $user->hasSecondFactor() ? count(TwoFactorAccount::recoveryCodes($user)) : 0;
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
