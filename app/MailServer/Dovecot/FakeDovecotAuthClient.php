<?php

namespace App\MailServer\Dovecot;

use App\MailServer\Models\Mailbox;
use LogicException;

/**
 * The e2e environment's stand-in for Dovecot's auth socket (Playwright runs without a mail server):
 * checks the stored `{BLF-CRYPT}` hash with password_verify(). Any other scheme is refused.
 *
 * AppServiceProvider binds it only when APP_ENV is e2e, and it refuses to run anywhere else; the
 * doctor's runtime.test_environment check fails an e2e or testing environment on the production mail
 * root. The Pest suite binds its own fake, and mail-truth tests use real Dovecot.
 */
class FakeDovecotAuthClient implements PasswordChecker
{
    private const SCHEME = '{BLF-CRYPT}';

    public function verify(string $user, #[\SensitiveParameter] string $password, ?string $remoteIp = null): bool
    {
        if (! app()->environment('e2e')) {
            throw new LogicException('FakeDovecotAuthClient checks passwords only when APP_ENV is e2e.');
        }

        $row = Mailbox::query()->where('email', $user)->first();
        $hash = $row?->getAttribute('password');
        if (! is_string($hash) || ! str_starts_with($hash, self::SCHEME)) {
            return false;
        }

        return password_verify($password, substr($hash, strlen(self::SCHEME)));
    }
}
