<?php

namespace App\Auth;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Pads the paths that find no usable account (sign-in, forgotten password) with one password hash
 * check, so they take as long as the paths that hash for a real user.
 */
final class DummyHash
{
    private static ?string $hash = null;

    /** One Hash::check against a hash built on first use with the configured driver and cost. */
    public static function check(string $value): void
    {
        self::$hash ??= Hash::make(Str::random(32));
        Hash::check($value, self::$hash);
    }
}
