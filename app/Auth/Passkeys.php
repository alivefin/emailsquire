<?php

namespace App\Auth;

use App\Http\Middleware\RequireTwoFactor;
use App\Mail\Notify;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Passkeys as PackagePasskeys;
use Laravel\Passkeys\Support\WebAuthn;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;

/**
 * A console user's passkeys: the one place the app touches laravel/passkeys (its options builder,
 * WebAuthn serializer and webauthn-lib validators, configured from `passkeys.*`) and the `passkeys`
 * table. Controllers call this, never the package.
 *
 * A ceremony's options are kept in the session with the user and the time, and are good for one
 * attempt within 5 minutes, whatever its outcome. Every refused registration is the same
 * PasskeyRefused::failed(). Additions, renames and removals are audited by name only (never the
 * credential, the challenge or a recovery code) in the transaction that makes them; additions and
 * removals email the user.
 */
final class Passkeys
{
    public const MAX_PER_USER = 10;

    /** How long a challenge is good for, and the browser's ceremony timeout (config `passkeys.timeout`). */
    public const CHALLENGE_SECONDS = 300;

    private const REGISTRATION_KEY = 'passkeys.registration';

    public function __construct(
        private readonly PasskeyRegistrationOptions $options,
        private readonly TwoFactorAccount $twoFactor,
        private readonly AuditLog $audit,
    ) {}

    /** @return Collection<int, Passkey> the user's passkeys, disabled ones included, oldest first */
    public function list(User $user): Collection
    {
        return self::of($user)->orderBy('created_at')->orderBy('id')->get();
    }

    /** @return array{id: int, name: string, created_at: string|null, last_used_at: string|null, disabled: bool} */
    public static function present(Passkey $passkey): array
    {
        return [
            'id' => $passkey->id,
            'name' => $passkey->name,
            'created_at' => $passkey->created_at?->toIso8601String(),
            'last_used_at' => $passkey->last_used_at?->toIso8601String(),
            'disabled' => $passkey->isDisabled(),
        ];
    }

    /**
     * Creation options for a new passkey of $user, for `navigator.credentials.create()` (`publicKey`,
     * binary values base64url). The options replace any earlier ones in the session.
     *
     * @return array<array-key, mixed>
     *
     * @throws PasskeyRefused too_many_passkeys when the user already has the most allowed
     */
    public function registrationOptions(User $user, Session $session): array
    {
        if (self::of($user)->count() >= self::MAX_PER_USER) {
            throw PasskeyRefused::tooMany();
        }

        $options = ($this->options)($user);
        $session->put(self::REGISTRATION_KEY, [
            'user_id' => $user->id,
            'issued_at' => now()->getTimestamp(),
            'options' => WebAuthn::toJson($options),
        ]);

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Verifies $credential (the browser's `PublicKeyCredential` as JSON) against the options in the
     * session, which this spends, and stores it as $name. If the user had no second factor before,
     * they get 8 new recovery codes, returned here this once; otherwise their codes stay as they are.
     *
     * @param  array<array-key, mixed>  $credential
     * @return array{passkey: Passkey, recovery_codes: list<string>|null}
     *
     * @throws PasskeyRefused
     */
    public function register(User $user, Session $session, string $name, array $credential): array
    {
        $record = $this->verifyAttestation($user, $session->pull(self::REGISTRATION_KEY), $credential);
        $credentialId = Base64UrlSafe::encodeUnpadded($record->publicKeyCredentialId);

        try {
            $result = DB::transaction(function () use ($user, $name, $record, $credentialId): array {
                // The user row's lock serialises this with other registrations (the limit) and with
                // removals and turning TOTP off (the last second factor and the recovery codes).
                $locked = User::query()->lockForUpdate()->find($user->id);
                if (! $locked instanceof User) {
                    throw PasskeyRefused::failed();
                }
                if (self::of($locked)->count() >= self::MAX_PER_USER) {
                    throw PasskeyRefused::tooMany();
                }
                if (Passkey::query()->where('credential_id', $credentialId)->exists()) {
                    throw PasskeyRefused::failed();
                }

                $first = ! $locked->hasSecondFactor();
                $passkey = new Passkey;
                $passkey->forceFill([
                    'user_id' => $locked->id,
                    'name' => $name,
                    'credential_id' => $credentialId,
                    'credential' => json_decode(WebAuthn::toJson($record), true, flags: JSON_THROW_ON_ERROR),
                ])->save();

                $codes = null;
                if ($first) {
                    $this->twoFactor->freshRecoveryCodes($locked);
                    $codes = TwoFactorAccount::recoveryCodes($locked);
                }
                $this->audit->record('user.passkey_added', 'user', $locked->email, changes: ['name' => $name]);
                $user->setRawAttributes($locked->getAttributes(), true);

                return ['passkey' => $passkey, 'recovery_codes' => $codes];
            });
        } catch (UniqueConstraintViolationException) {
            // Another request stored the same credential between the check and the insert.
            throw PasskeyRefused::failed();
        }

        Notify::security($user, 'passkey_added');

        return $result;
    }

    /**
     * Renames one of the user's passkeys; audited when the name changes.
     *
     * @throws ModelNotFoundException when it isn't one of theirs (404)
     */
    public function rename(User $user, int $id, string $name): Passkey
    {
        return DB::transaction(function () use ($user, $id, $name): Passkey {
            $passkey = self::of($user)->whereKey($id)->lockForUpdate()->firstOrFail();
            $old = $passkey->name;
            if ($old !== $name) {
                $passkey->name = $name;
                $passkey->save();
                $this->audit->record('user.passkey_renamed', 'user', $user->email, changes: ['name' => [$old, $name]]);
            }

            return $passkey;
        });
    }

    /**
     * Removes one of the user's passkeys and emails them. Removing their last second factor (a usable
     * passkey, with no other usable one and no confirmed TOTP) is refused while two-factor is required
     * for them; otherwise it also removes the recovery codes.
     *
     * @throws Conflict last_second_factor
     * @throws ModelNotFoundException when it isn't one of theirs (404)
     */
    public function delete(User $user, int $id): void
    {
        DB::transaction(function () use ($user, $id): void {
            $locked = User::query()->lockForUpdate()->findOrFail($user->id);
            $passkey = self::of($locked)->whereKey($id)->firstOrFail();

            $lastFactor = ! $passkey->isDisabled()
                && ! $locked->hasEnabledTwoFactorAuthentication()
                && ! $locked->usablePasskeys()->whereKeyNot($passkey->id)->exists();
            if ($lastFactor && RequireTwoFactor::requiredFor($locked)) {
                throw new Conflict('last_second_factor', trans()->string('emailsquire.two_factor.last_second_factor'));
            }

            $passkey->delete();
            if (! $locked->hasSecondFactor()) {
                $this->twoFactor->dropRecoveryCodes($locked);
            }
            $this->audit->record('user.passkey_removed', 'user', $locked->email, changes: ['name' => $passkey->name]);
            $user->setRawAttributes($locked->getAttributes(), true);
        });

        Notify::security($user, 'passkey_removed');
    }

    /** @return Builder<Passkey> all of $user's passkeys, disabled ones included */
    private static function of(User $user): Builder
    {
        return Passkey::query()->where('user_id', $user->id);
    }

    /**
     * The checked credential record, or PasskeyRefused::failed() for anything wrong: the pending
     * options missing, for another user or older than 5 minutes; a malformed credential; or a ceremony
     * webauthn-lib refuses (challenge, origin, RP ID hash, user presence and verification, algorithm,
     * attestation `none`, credential id). webauthn-lib accepts either client data type in either
     * ceremony, so the type is checked here.
     *
     * @param  array<array-key, mixed>  $credential
     */
    private function verifyAttestation(User $user, mixed $pending, array $credential): CredentialRecord
    {
        if (! is_array($pending)
            || ($pending['user_id'] ?? null) !== $user->id
            || ! is_int($pending['issued_at'] ?? null)
            || ! is_string($pending['options'] ?? null)
            || now()->getTimestamp() - $pending['issued_at'] > self::CHALLENGE_SECONDS) {
            throw PasskeyRefused::failed();
        }

        try {
            $options = WebAuthn::fromJson($pending['options'], PublicKeyCredentialCreationOptions::class);
            // A credential without an `id` comes back as the input array, not an object: reading its
            // properties throws, which ends up below like any other malformed input.
            $publicKeyCredential = WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
            $response = $publicKeyCredential->response;
            if (! $response instanceof AuthenticatorAttestationResponse
                || $publicKeyCredential->type !== 'public-key'
                || $response->clientDataJSON->type !== 'webauthn.create') {
                throw PasskeyRefused::failed();
            }

            return WebAuthn::attestationValidator()->check($response, $options, PackagePasskeys::relyingPartyId());
        } catch (PasskeyRefused $e) {
            throw $e;
        } catch (Throwable $e) {
            // webauthn-lib's verification messages name the failed check and carry no credential data;
            // other exceptions (malformed input) may quote the input, so only their class is logged.
            Log::info('Passkey registration refused.', [
                'user_id' => $user->id,
                'reason' => $e instanceof AuthenticatorResponseVerificationException ? $e->getMessage() : $e::class,
            ]);

            throw PasskeyRefused::failed();
        }
    }
}
