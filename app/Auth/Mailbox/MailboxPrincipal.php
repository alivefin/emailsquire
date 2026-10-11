<?php

namespace App\Auth\Mailbox;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * A mailbox owner signed in to the portal (the `mailbox` guard). Read-only and never stored: the
 * session keeps only the address, and MailboxUserProvider builds this afresh from `virtual_users` on
 * every request, so a deleted or disabled mailbox has no principal at its next request.
 *
 * The password hash is held only for the session guard: SessionGuard::login() and AuthenticateSession
 * keep an HMAC of it in the session, so a password changed anywhere (the console, Roundcube, the
 * portal) ends the mailbox's other portal sessions at their next request.
 */
final readonly class MailboxPrincipal implements Authenticatable
{
    /**
     * @param  string  $address  the guide row's spelling (legacy rows may be mixed case)
     * @param  int  $quotaBytes  the effective quota: a stored 0 is `quota_default_bytes`
     */
    public function __construct(
        public string $address,
        public int $domainId,
        public string $domainName,
        public int $quotaBytes,
        #[\SensitiveParameter] private string $passwordHash,
    ) {}

    public function getAuthIdentifierName(): string
    {
        return 'address';
    }

    public function getAuthIdentifier(): string
    {
        return $this->address;
    }

    public function getAuthPasswordName(): string
    {
        return 'password';
    }

    public function getAuthPassword(): string
    {
        return $this->passwordHash;
    }

    /** No remember-me for mailboxes: nothing can re-authenticate a session after it ends. */
    public function getRememberToken(): ?string
    {
        return null;
    }

    public function setRememberToken($value): void {}

    public function getRememberTokenName(): string
    {
        return '';
    }

    /** @return array<string, int|string> never the hash, should the principal end up in a dump */
    public function __debugInfo(): array
    {
        return ['address' => $this->address, 'domainId' => $this->domainId, 'domainName' => $this->domainName, 'quotaBytes' => $this->quotaBytes];
    }
}
