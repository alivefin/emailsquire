<?php

namespace App\MailServer\Dovecot;

use InvalidArgumentException;

/**
 * Checks a mailbox password the way the mail server does. Production binds DovecotAuthClient (the
 * auth socket); the e2e environment, and only it, binds FakeDovecotAuthClient (AppServiceProvider).
 */
interface PasswordChecker
{
    /**
     * Whether $password is $user's mail password. $remoteIp is the signing-in client's address, so
     * Dovecot's auth penalty and auth log are per client.
     *
     * @throws DovecotAuthException when the check could not be made (socket missing, timeout, protocol)
     * @throws InvalidArgumentException when $remoteIp is not an IP address
     */
    public function verify(string $user, #[\SensitiveParameter] string $password, ?string $remoteIp = null): bool;
}
