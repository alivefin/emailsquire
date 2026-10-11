<?php

namespace App\Auth;

use App\Http\Middleware\RequireTwoFactor;
use App\Mail\Notify;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Passkeys\Passkeys as PackagePasskeys;
use Laravel\Passkeys\Support\WebAuthn;
use LogicException;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Throwable;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CredentialRecord;
use Webauthn\Exception\AuthenticatorResponseVerificationException;
use Webauthn\Exception\CounterException;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * A console user's passkeys: the one place the app touches laravel/passkeys (its options builder,
 * WebAuthn serializer and webauthn-lib validators, configured from `passkeys.*`) and the `passkeys`
 * table. Controllers call this, never the package.
 *
 * A ceremony's options are kept in the session with the user, the time and a random nonce, and are
 * good for one attempt within 5 minutes, whatever its outcome: the session entry is pulled, and the
 * nonce is spent with one atomic Cache::add, since two requests on one session can both read the
 * entry before either writes the session back (Laravel doesn't lock sessions). Every refused
 * registration is the same PasskeyRefused::failed(). Additions, renames and removals are audited by
 * name only (never the credential, the challenge or a recovery code) in the transaction that makes
 * them; additions and removals email the user.
 *
 * Assertions (verifyAssertion()) serve three ceremonies, each with its own options in the session:
 * the passwordless sign-in (SIGN_IN, discoverable credentials, no user named), the two-factor step
 * (TWO_FACTOR, the pending user's passkeys) and step-up (STEP_UP, the signed-in user's). Every refusal
 * is the same `passkey_failed` and writes the fail2ban line with `kind=passkey`; a signature counter
 * that doesn't go up disables the passkey, emails the user and answers `passkey_cloned`. A disabled
 * passkey never verifies.
 */
final class Passkeys
{
    public const MAX_PER_USER = 10;

    /** How long a challenge is good for, and the browser's ceremony timeout (config `passkeys.timeout`). */
    public const CHALLENGE_SECONDS = 300;

    /** The passwordless sign-in (POST /auth/passkey). */
    public const SIGN_IN = 'login';

    /** The second step of a password sign-in (POST /auth/two-factor-challenge). */
    public const TWO_FACTOR = 'two_factor';

    /** Step-up (POST /auth/confirm-password). */
    public const STEP_UP = 'step_up';

    private const REGISTRATION_KEY = 'passkeys.registration';

    private const ASSERTION_KEY = 'passkeys.assertion.';

    /** Every session key a ceremony keeps its options under; a sign-in drops them all (RecordSignIn). */
    public const SESSION_KEYS = [
        self::REGISTRATION_KEY,
        self::ASSERTION_KEY.self::SIGN_IN,
        self::ASSERTION_KEY.self::TWO_FACTOR,
        self::ASSERTION_KEY.self::STEP_UP,
    ];

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
        self::keep($session, self::REGISTRATION_KEY, $user->id, WebAuthn::toJson($options));

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
        $record = $this->verifyAttestation($user, self::spend($session, self::REGISTRATION_KEY, $user->id), $credential);
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
     * The checked credential record, or PasskeyRefused::failed() for anything wrong: no pending options
     * (missing, for another user, older than 5 minutes or already spent: spend()); a malformed
     * credential; or a ceremony webauthn-lib refuses (challenge, origin, RP ID hash, user presence and
     * verification, algorithm, attestation `none`, credential id). webauthn-lib accepts either client
     * data type in either ceremony, so the type is checked here.
     *
     * @param  array<array-key, mixed>  $credential
     */
    private function verifyAttestation(User $user, ?string $pending, array $credential): CredentialRecord
    {
        if ($pending === null) {
            throw PasskeyRefused::failed();
        }

        try {
            $options = WebAuthn::fromJson($pending, PublicKeyCredentialCreationOptions::class);
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

    /**
     * Request options for `navigator.credentials.get()` (`publicKey`, binary values base64url) for
     * $purpose, replacing earlier ones of that ceremony in the session. The passwordless sign-in names
     * no user and lists no credential (discoverable credentials), so it says nothing about which
     * accounts exist; the two-factor step and step-up list $user's usable passkeys only.
     *
     * @return array<array-key, mixed>
     *
     * @throws PasskeyRefused passkey_failed when $user has no usable passkey
     */
    public function assertionOptions(Session $session, string $purpose, ?User $user = null): array
    {
        self::checkPurpose($purpose, $user);

        $allow = [];
        if ($user !== null) {
            foreach ($user->usablePasskeys()->orderBy('id')->pluck('credential_id') as $id) {
                if (is_string($id)) {
                    $allow[] = PublicKeyCredentialDescriptor::create(PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY, Base64UrlSafe::decodeNoPadding($id));
                }
            }
            if ($allow === []) {
                throw PasskeyRefused::failed(self::field($purpose));
            }
        }

        $options = PublicKeyCredentialRequestOptions::create(
            challenge: random_bytes(32),
            rpId: PackagePasskeys::relyingPartyId(),
            allowCredentials: $allow,
            userVerification: AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: PackagePasskeys::timeout(),
        );
        self::keep($session, self::ASSERTION_KEY.$purpose, $user?->id, WebAuthn::toJson($options));

        return WebAuthn::toBrowserArray($options);
    }

    /**
     * Verifies $credential (the browser's `PublicKeyCredential` from `navigator.credentials.get()`, as
     * JSON) against the $purpose options in the session, which this spends, and answers the passkey's
     * owner. For the two-factor step and step-up the passkey must be $user's; the passwordless sign-in
     * takes any passkey whose response carries its owner's user handle. Either way the owner must be
     * active with a password set, and the passkey not disabled. A verified assertion stores the new
     * signature counter and `last_used_at`.
     *
     * @param  array<array-key, mixed>  $credential
     *
     * @throws PasskeyRefused passkey_failed for anything wrong, passkey_cloned when the counter didn't go up
     */
    public function verifyAssertion(Session $session, string $purpose, ?User $user, array $credential): User
    {
        self::checkPurpose($purpose, $user);
        $field = self::field($purpose);
        $pending = self::spend($session, self::ASSERTION_KEY.$purpose, $user?->id);

        try {
            if ($pending === null) {
                throw PasskeyRefused::failed($field);
            }
            $options = WebAuthn::fromJson($pending, PublicKeyCredentialRequestOptions::class);
            // A credential without an `id` comes back as the input array, not an object (see verifyAttestation()).
            $publicKeyCredential = WebAuthn::fromJson(json_encode($credential, JSON_THROW_ON_ERROR), PublicKeyCredential::class);
            $response = $publicKeyCredential->response;
            if (! $response instanceof AuthenticatorAssertionResponse
                || $publicKeyCredential->type !== 'public-key'
                || $response->clientDataJSON->type !== 'webauthn.get') {
                throw PasskeyRefused::failed($field);
            }
            $credentialId = Base64UrlSafe::encodeUnpadded($publicKeyCredential->rawId);
        } catch (PasskeyRefused $e) {
            $this->refused($purpose, $user, $pending === null ? 'no pending options' : 'not an assertion');

            throw $e;
        } catch (Throwable $e) {
            $this->refused($purpose, $user, $e::class);

            throw PasskeyRefused::failed($field);
        }

        $outcome = DB::transaction(
            /** @return array{0: 'ok'|'refused'|'cloned', 1: Passkey|null, 2: User|null, 3: string} */
            function () use ($credentialId, $user, $purpose, $response, $options): array {
                // The row lock serialises two assertions of one passkey, so each sees the other's counter.
                $passkey = Passkey::query()->where('credential_id', $credentialId)->lockForUpdate()->first();
                if (! $passkey instanceof Passkey) {
                    return ['refused', null, null, 'unknown credential'];
                }
                $owner = User::query()->find($passkey->user_id);
                if (! $owner instanceof User) {
                    return ['refused', $passkey, null, 'no owner'];
                }
                if ($user !== null && $passkey->user_id !== $user->id) {
                    return ['refused', $passkey, $owner, 'another user\'s passkey'];
                }
                if ($passkey->isDisabled()) {
                    return ['refused', $passkey, $owner, 'disabled passkey'];
                }
                if (! $owner->isActive() || $owner->password_set_at === null) {
                    return ['refused', $passkey, $owner, 'account cannot sign in'];
                }

                try {
                    $record = WebAuthn::fromJson(json_encode($passkey->credential, JSON_THROW_ON_ERROR), CredentialRecord::class);
                    // The passwordless sign-in named no user, so the response must carry the owner's handle
                    // (null here); the other ceremonies identified the user before.
                    $checked = WebAuthn::assertionValidator()->check(
                        $record,
                        $response,
                        $options,
                        PackagePasskeys::relyingPartyId(),
                        $purpose === self::SIGN_IN ? null : $record->userHandle,
                    );
                } catch (CounterException) {
                    // A valid signature with a counter that didn't go up: two authenticators hold this key.
                    // Committed with the transaction, which returns normally.
                    $passkey->forceFill(['disabled_at' => now()])->save();

                    return ['cloned', $passkey, $owner, 'signature counter did not go up'];
                } catch (Throwable $e) {
                    return ['refused', $passkey, $owner, $e instanceof AuthenticatorResponseVerificationException ? $e->getMessage() : $e::class];
                }

                $passkey->forceFill([
                    'credential' => json_decode(WebAuthn::toJson($checked), true, flags: JSON_THROW_ON_ERROR),
                    'last_used_at' => now(),
                ])->save();

                return ['ok', $passkey, $owner, ''];
            });

        [$result, $passkey, $owner, $reason] = $outcome;
        if ($result === 'ok' && $owner instanceof User) {
            return $owner;
        }

        $this->refused($purpose, $user ?? $owner, $reason, $passkey?->id);
        if ($result === 'cloned' && $owner instanceof User) {
            Log::warning('Passkey disabled: its signature counter did not go up, so it may have been copied.', [
                'user_id' => $owner->id,
                'passkey_id' => $passkey?->id,
            ]);
            Notify::security($owner, 'passkey_cloned');

            throw PasskeyRefused::cloned($field);
        }

        throw PasskeyRefused::failed($field);
    }

    /**
     * A refused assertion: one info line saying why (a static description, never credential data) and
     * the fail2ban line (AuthFailureLogger, `kind=passkey`) naming $who, or `-` when nobody is known.
     */
    private function refused(string $purpose, ?User $who, string $reason, ?int $passkeyId = null): void
    {
        Log::info('Passkey assertion refused.', [
            'purpose' => $purpose,
            'user_id' => $who?->id,
            'passkey_id' => $passkeyId,
            'reason' => $reason,
        ]);
        event(new Failed('web', $who, ['email' => $who->email ?? '', 'method' => 'passkey']));
    }

    /** Keeps a ceremony's options in the session under $key, for $userId, with the time and a fresh nonce. */
    private static function keep(Session $session, string $key, ?int $userId, string $options): void
    {
        $session->put($key, [
            'user_id' => $userId,
            'issued_at' => now()->getTimestamp(),
            'nonce' => bin2hex(random_bytes(16)),
            'options' => $options,
        ]);
    }

    /**
     * Takes the options under $key out of the session and answers them (JSON), or null when they are
     * missing, malformed, for another user ($userId), older than 5 minutes or already spent. The nonce
     * is spent with one atomic Cache::add: the session entry alone isn't single-use across concurrent
     * requests, which can each read it before either writes the session back.
     */
    private static function spend(Session $session, string $key, ?int $userId): ?string
    {
        $pending = $session->pull($key);
        if (! is_array($pending)
            || ($pending['user_id'] ?? null) !== $userId
            || ! is_int($pending['issued_at'] ?? null)
            || ! is_string($pending['options'] ?? null)
            || ! is_string($pending['nonce'] ?? null)
            || now()->getTimestamp() - $pending['issued_at'] > self::CHALLENGE_SECONDS) {
            return null;
        }

        // Kept a minute past the options' lifetime, by when they are refused as expired anyway.
        return Cache::add('passkeys:challenge:'.$pending['nonce'], true, self::CHALLENGE_SECONDS + 60) ? $pending['options'] : null;
    }

    /** The request field a refusal of $purpose is reported on. */
    private static function field(string $purpose): string
    {
        return $purpose === self::SIGN_IN ? 'credential' : 'passkey';
    }

    private static function checkPurpose(string $purpose, ?User $user): void
    {
        $ok = match ($purpose) {
            self::SIGN_IN => $user === null,
            self::TWO_FACTOR, self::STEP_UP => $user !== null,
            default => false,
        };
        if (! $ok) {
            throw new LogicException("Passkey assertions for '{$purpose}' ".($user === null ? 'need a user.' : 'name no user.'));
        }
    }
}
