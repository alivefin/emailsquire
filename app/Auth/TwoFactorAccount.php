<?php

namespace App\Auth;

use App\Http\Middleware\RequireTwoFactor;
use App\Mail\Notify;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\Models\Passkey;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;
use Laravel\Fortify\Fortify;

/**
 * Two-factor changes that are audited, each written in one transaction with its audit row. Audit
 * rows name the user by email and carry no `changes`: never a secret or a code.
 */
final class TwoFactorAccount
{
    public function __construct(
        private readonly EnableTwoFactorAuthentication $enable,
        private readonly ConfirmTwoFactorAuthentication $confirm,
        private readonly DisableTwoFactorAuthentication $disable,
        private readonly GenerateNewRecoveryCodes $generate,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Starts (or restarts) setup with a fresh secret and recovery codes, decided on the row read under
     * a lock rather than on $user, which may be stale: if a concurrent confirm has committed since
     * $user was loaded, a forced new secret would leave the account confirmed with a secret the user
     * never saw. $user is refreshed from that row. False when two-factor is confirmed by now.
     * Unconfirmed, so not audited (`user.two_factor_enabled` is written on confirm).
     */
    public function enable(User $user): bool
    {
        return DB::transaction(function () use ($user): bool {
            $locked = User::query()->lockForUpdate()->find($user->id);
            if (! $locked instanceof User) {
                return false;
            }
            $user->setRawAttributes($locked->getAttributes(), true);
            if ($locked->hasEnabledTwoFactorAuthentication()) {
                return false;
            }

            // force: setting up again before confirming replaces the secret nobody has confirmed. Fortify
            // also makes new recovery codes; a user whose passkey already brought codes keeps those.
            $codes = $locked->hasPasskeysEnabled() ? $locked->two_factor_recovery_codes : null;
            ($this->enable)($locked, true);
            if ($codes !== null) {
                $locked->forceFill(['two_factor_recovery_codes' => $codes])->save();
            }
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Confirms the setup with a code checked against the secret stored now, read under a row lock: a
     * concurrent setup (another tab forcing a new secret) can't get a secret confirmed that the user
     * never saw. $user is refreshed from that row. False when there is no unconfirmed setup any more.
     *
     * @throws ValidationException when the code does not match the stored secret
     */
    public function confirm(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $locked = User::query()->lockForUpdate()->find($user->id);
            if (! $locked instanceof User) {
                return false;
            }
            $user->setRawAttributes($locked->getAttributes(), true);
            if ($locked->two_factor_secret === null || $locked->hasEnabledTwoFactorAuthentication()) {
                return false;
            }

            ($this->confirm)($locked, $code);
            // A reset clears the codes of a setup left unconfirmed; the confirmed factor brings new ones.
            if ($locked->two_factor_recovery_codes === null) {
                ($this->generate)($locked);
            }
            $this->audit->record('user.two_factor_enabled', 'user', $locked->email);
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }

    /**
     * Turns confirmed two-factor off and emails the user; an unconfirmed setup is just dropped. Decided
     * on the row read under a lock, which also serialises this with passkey changes (App\Auth\Passkeys).
     * The recovery codes stay while a usable passkey does. Turning off the last second factor (no usable
     * passkey) is refused while two-factor is required for the user.
     *
     * @throws Conflict last_second_factor
     */
    public function disable(User $user): void
    {
        $disabled = DB::transaction(function () use ($user): bool {
            $locked = User::query()->lockForUpdate()->find($user->id);
            if (! $locked instanceof User) {
                return false;
            }
            $confirmed = $locked->hasEnabledTwoFactorAuthentication();
            $passkey = $locked->hasPasskeysEnabled();
            if ($confirmed && ! $passkey && RequireTwoFactor::requiredFor($locked)) {
                throw new Conflict('last_second_factor', trans()->string('emailsquire.two_factor.last_second_factor'));
            }

            $codes = $passkey ? $locked->two_factor_recovery_codes : null;
            ($this->disable)($locked);
            if ($codes !== null) {
                $locked->forceFill(['two_factor_recovery_codes' => $codes])->save();
            }
            // Enabling wrote no audit row or notice, so cancelling an unconfirmed setup writes none either.
            if ($confirmed) {
                $this->audit->record('user.two_factor_disabled', 'user', $locked->email);
            }
            $user->setRawAttributes($locked->getAttributes(), true);

            return $confirmed;
        });

        if ($disabled) {
            Notify::security($user, 'two_factor_disabled');
        }
    }

    /**
     * Turns another user's second factors off, for a user who lost their authenticator, their passkeys
     * and the recovery codes (passkeys spec decision 10): under a row lock, deletes every passkey
     * (disabled ones too), clears a confirmed TOTP secret and its confirmation, and the recovery codes;
     * audits it and emails the user. A server admin does it with POST /users/{user}/two-factor/reset
     * (audited as `user.two_factor_reset`, the notice naming the request's IP); an operator with
     * `emailsquire:reset-two-factor` ($fromConsole: audited as `user.two_factor_disabled`, as since 2b,
     * the notice naming no IP). False, changing nothing, when $target has neither confirmed TOTP nor a
     * passkey. An unconfirmed TOTP secret is left alone, but its recovery codes are cleared too.
     */
    public function reset(User $target, bool $fromConsole = false): bool
    {
        $reset = DB::transaction(function () use ($target, $fromConsole): bool {
            $locked = User::query()->lockForUpdate()->find($target->id);
            if (! $locked instanceof User) {
                return false;
            }
            $totp = $locked->hasEnabledTwoFactorAuthentication();
            $passkeys = Passkey::query()->where('user_id', $locked->id);
            if (! $totp && ! $passkeys->exists()) {
                return false;
            }

            $passkeys->delete();
            if ($totp) {
                ($this->disable)($locked);
            } else {
                $locked->forceFill(['two_factor_recovery_codes' => null])->save();
            }
            $this->audit->record($fromConsole ? 'user.two_factor_disabled' : 'user.two_factor_reset', 'user', $locked->email);
            $target->setRawAttributes($locked->getAttributes(), true);

            return true;
        });

        if ($reset) {
            // After the outermost transaction commits: ConsoleUsers runs this inside its own, which a
            // later check may still roll back.
            DB::afterCommit(fn () => Notify::security($target, 'two_factor_disabled', fromRequest: ! $fromConsole));
        }

        return $reset;
    }

    /**
     * Gives $locked 8 new recovery codes, unaudited: the first second factor (a passkey) brought them.
     * Runs inside the caller's transaction, on the row it locked.
     */
    public function freshRecoveryCodes(User $locked): void
    {
        ($this->generate)($locked);
    }

    /**
     * Removes the recovery codes of $locked, who has no second factor left. An unconfirmed TOTP setup
     * gets new ones instead, never shown yet: its confirm answers them. Runs inside the caller's
     * transaction, on the row it locked.
     */
    public function dropRecoveryCodes(User $locked): void
    {
        if ($locked->two_factor_secret !== null) {
            ($this->generate)($locked);

            return;
        }

        $locked->forceFill(['two_factor_recovery_codes' => null])->save();
    }

    public function regenerateRecoveryCodes(User $user): void
    {
        DB::transaction(function () use ($user) {
            ($this->generate)($user);
            $this->audit->record('user.recovery_codes_regenerated', 'user', $user->email);
        });
    }

    /** @return list<string> */
    public static function recoveryCodes(User $user): array
    {
        $codes = $user->two_factor_recovery_codes === null ? [] : $user->recoveryCodes();

        return array_values(array_filter($codes, 'is_string'));
    }

    /**
     * Spends a recovery code: removes it (Fortify's own challenge would replace it with a new one), under
     * a row lock so two requests can't both spend the same code. Not audited, like any sign-in.
     */
    public function consumeRecoveryCode(User $user, string $given): bool
    {
        return DB::transaction(function () use ($user, $given): bool {
            $locked = User::query()->lockForUpdate()->find($user->id);
            if (! $locked instanceof User) {
                return false;
            }

            $codes = self::recoveryCodes($locked);
            $match = null;
            foreach ($codes as $i => $code) {
                if (hash_equals($code, $given)) {
                    $match = $i;
                }
            }
            if ($match === null) {
                return false;
            }

            array_splice($codes, $match, 1);
            $locked->forceFill([
                'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt((string) json_encode($codes)),
            ])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return true;
        });
    }
}
