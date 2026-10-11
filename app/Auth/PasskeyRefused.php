<?php

namespace App\Auth;

use RuntimeException;

/**
 * A passkey registration the app won't accept; rendered as 422 with the message on `credential`.
 * `passkey_failed` is the one answer for every ceremony that doesn't verify (wrong origin or RP ID, a
 * missing, expired, replayed or foreign challenge, no user verification, a malformed or known
 * credential), so the answer says nothing about which check failed. `too_many_passkeys`: the user
 * already has the most allowed.
 */
final class PasskeyRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $detail)
    {
        parent::__construct($detail);
    }

    public static function failed(): self
    {
        return new self('passkey_failed', trans()->string('emailsquire.passkeys.failed'));
    }

    public static function tooMany(): self
    {
        return new self('too_many_passkeys', trans()->string('emailsquire.passkeys.too_many', ['max' => Passkeys::MAX_PER_USER]));
    }
}
