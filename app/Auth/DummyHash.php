<?php

namespace App\Auth;

use Illuminate\Support\Facades\Hash;

/**
 * Pads the paths that find no usable account (sign-in, forgotten password) with one password hash
 * check, so they take as long as the paths that hash for a real user.
 */
final class DummyHash
{
    // Precomputed from discarded random values. The payload is never used to authenticate anyone.
    private const BCRYPT = '$2y$12$A2dLw8kSRXF6TNcS7YjsGu1PJ1UAsyVT3WgynRJvdLMOjJmytb5gK';

    private const ARGON = '$NCvXaXHiQa7SN+XwvM2BnA$aTxnxad1wlwH559T8wPgnrk2l4pEnL5TR3opYfb49tU';

    private const ARGON2ID = '$GEPYVQCdimClnIYCp2qzRw$Sz0o1Mx161eM1eICM+c07vCccXuKujrtw9ler3hT5bs';

    /** One verification, including on the first call, with the configured algorithm and work factors. */
    public static function check(string $value): void
    {
        // Verification takes its work factors from the encoded header. Reusing a fixed salt/digest
        // with that header costs exactly as much as a real hash; no request generates a dummy hash.
        $driver = config('hashing.driver');
        $hash = match ($driver) {
            'bcrypt' => '$2y$'.sprintf('%02d', self::workFactor('hashing.bcrypt.rounds')).'$'.substr(self::BCRYPT, 7),
            'argon', 'argon2id' => '$'.($driver === 'argon' ? 'argon2i' : 'argon2id').'$v=19$m='
                .self::workFactor('hashing.argon.memory').',t='.self::workFactor('hashing.argon.time').',p='.self::workFactor('hashing.argon.threads')
                .($driver === 'argon' ? self::ARGON : self::ARGON2ID),
            default => throw new \LogicException('Unsupported console hash driver'),
        };
        Hash::check($value, $hash);
    }

    /** Laravel environment-backed hashing options can be integers or decimal strings. */
    private static function workFactor(string $key): int
    {
        $value = config($key);
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (! is_int($value) || $value < 1) {
            throw new \LogicException('Invalid console hash work factor');
        }

        return $value;
    }
}
