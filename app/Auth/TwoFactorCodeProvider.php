<?php

namespace App\Auth;

use Illuminate\Contracts\Cache\Repository;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

/**
 * Verifies TOTP codes for every path that takes one (sign-in challenge, step-up, setup confirm), bound
 * in place of Fortify's provider. A code is accepted once: its 30-second period is marked used under a
 * key of that secret and period, with one atomic Cache::add, so of two parallel requests only the one
 * whose add succeeds passes. The key holds an HMAC of the secret (never the secret or the code), so one
 * user's code never blocks the same digits for another user.
 */
final class TwoFactorCodeProvider implements TwoFactorAuthenticationProvider
{
    public function __construct(
        private readonly Google2FA $engine,
        private readonly Repository $cache,
    ) {}

    public function generateSecretKey(int $secretLength = 16)
    {
        return $this->engine->generateSecretKey($secretLength);
    }

    public function qrCodeUrl($companyName, $companyEmail, $secret)
    {
        return $this->engine->getQRCodeUrl($companyName, $companyEmail, $secret);
    }

    /**
     * How long a used code stays marked: longer than any code stays valid. A code is accepted from
     * `window` periods before its own to `window` periods after it, so (2 × window + 1) periods of
     * 30 s, plus a minute for clock skew: 150 s for window 1.
     */
    public static function usedTtlSeconds(): int
    {
        return (2 * self::window() + 1) * 30 + 60;
    }

    private static function window(): int
    {
        $window = config('fortify-options.two-factor-authentication.window', 1);

        return is_int($window) && $window >= 0 ? $window : 1;
    }

    public function verify($secret, $code)
    {
        // With an old timestamp given (0), Google2FA answers the matching period rather than true.
        $period = $this->engine->verifyKeyNewer($secret, $code, 0, self::window());
        if (! is_int($period)) {
            return false;
        }

        $key = '2fa_used:'.hash_hmac('sha256', $secret, config()->string('app.key')).':'.$period;

        return $this->cache->add($key, true, self::usedTtlSeconds());
    }
}
