<?php

namespace App\MailServer;

/**
 * Classifies a stored Dovecot password hash (`{SCHEME}hash`) by strength.
 * Scheme names are compared case-insensitively, like Dovecot does.
 */
final class PasswordScheme
{
    public const STRONG = 'strong';

    public const LEGACY = 'legacy';

    public const WEAK = 'weak';

    public const UNKNOWN = 'unknown';

    private const STRONG_SCHEMES = ['BLF-CRYPT', 'ARGON2I', 'ARGON2ID'];

    private const LEGACY_SCHEMES = ['SHA256-CRYPT', 'SHA512-CRYPT'];

    private const WEAK_SCHEMES = [
        'PLAIN', 'CLEAR', 'CLEARTEXT', 'PLAIN-TRUNC', 'PLAIN-MD4', 'PLAIN-MD5', 'LDAP-MD5', 'MD5',
        'SMD5', 'SHA', 'SSHA', 'CRAM-MD5', 'DIGEST-MD5', 'LANMAN', 'NTLM', 'RPA',
    ];

    /** The upper-cased scheme name, or null when there is no `{SCHEME}` prefix. */
    public static function scheme(string $stored): ?string
    {
        if (preg_match('/^\{([A-Za-z0-9._-]+)\}/', $stored, $m) !== 1) {
            return null;
        }

        return strtoupper($m[1]);
    }

    /** @return self::STRONG|self::LEGACY|self::WEAK|self::UNKNOWN */
    public static function strength(string $stored): string
    {
        $scheme = self::scheme($stored);

        if ($scheme === null) {
            return self::UNKNOWN;
        }

        if ($scheme === 'CRYPT') {
            $hash = substr($stored, strlen('{CRYPT}'));

            return match (true) {
                preg_match('/^\$2[aby]\$/', $hash) === 1 => self::STRONG,
                preg_match('/^\$[56]\$/', $hash) === 1 => self::LEGACY,
                default => self::UNKNOWN,
            };
        }

        return match (true) {
            in_array($scheme, self::STRONG_SCHEMES, true) => self::STRONG,
            in_array($scheme, self::LEGACY_SCHEMES, true) => self::LEGACY,
            in_array($scheme, self::WEAK_SCHEMES, true) => self::WEAK,
            default => self::UNKNOWN,
        };
    }
}
