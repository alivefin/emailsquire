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
    /** Longer than any code stays valid: its own period plus the window's next one (at most 90 s). */
    public const USED_TTL_SECONDS = 150;

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

    public function verify($secret, $code)
    {
        $window = config('fortify-options.two-factor-authentication.window', 1);

        // With an old timestamp given (0), Google2FA answers the matching period rather than true.
        $period = $this->engine->verifyKeyNewer($secret, $code, 0, is_int($window) ? $window : 1);
        if (! is_int($period)) {
            return false;
        }

        $key = '2fa_used:'.hash_hmac('sha256', $secret, config()->string('app.key')).':'.$period;

        return $this->cache->add($key, true, self::USED_TTL_SECONDS);
    }
}
