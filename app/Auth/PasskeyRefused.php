<?php

namespace App\Auth;

use RuntimeException;

/**
 * A passkey ceremony the app won't accept; rendered as 422 with the message on `$field` (`credential`
 * for registration and the passwordless sign-in, `passkey` for the two-factor step and step-up).
 * `passkey_failed` is the one answer for every ceremony that doesn't verify (wrong origin or RP ID, a
 * missing, expired, replayed or foreign challenge, no user verification, a malformed, unknown, disabled
 * or someone else's credential, a deactivated account), so the answer says nothing about which check
 * failed. `passkey_cloned`: the signature counter didn't go up, so the credential was disabled.
 * `too_many_passkeys`: the user already has the most allowed.
 */
final class PasskeyRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $detail, public readonly string $field = 'credential')
    {
        parent::__construct($detail);
    }

    public static function failed(string $field = 'credential'): self
    {
        return new self('passkey_failed', trans()->string('emailsquire.passkeys.failed'), $field);
    }

    public static function cloned(string $field = 'credential'): self
    {
        return new self('passkey_cloned', trans()->string('emailsquire.passkeys.cloned'), $field);
    }

    public static function tooMany(): self
    {
        return new self('too_many_passkeys', trans()->string('emailsquire.passkeys.too_many', ['max' => Passkeys::MAX_PER_USER]));
    }
}
