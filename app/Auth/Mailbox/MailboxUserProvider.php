<?php

namespace App\Auth\Mailbox;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\Queries\MailboxLookup;
use App\MailServer\QuotaFormat;
use App\Models\DisabledMailbox;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use InvalidArgumentException;

/**
 * The `mailbox` guard's provider (config/auth.php): mailbox owners from the guide's `virtual_users`,
 * with passwords checked by the mail server (PasswordChecker). A mailbox that is gone or has a
 * `disabled_mailboxes` row has no principal, so its portal sessions end at their next request.
 * There are no remember tokens.
 */
class MailboxUserProvider implements UserProvider
{
    public function __construct(
        private readonly PasswordChecker $checker,
        private readonly MailboxLookup $lookup,
        private readonly AddressNormalizer $normalizer,
    ) {}

    /**
     * Re-reads the mailbox on every request. $identifier is the stored spelling the session holds;
     * only that exact spelling matches, so the session never moves to another row.
     */
    public function retrieveById($identifier): ?MailboxPrincipal
    {
        if (! is_string($identifier) || $identifier === '') {
            return null;
        }

        $row = Mailbox::query()->where('email', $identifier)->first();

        return $row !== null && $row->email === $identifier ? $this->principal($row) : null;
    }

    /** The enabled mailbox at $address (matched case-insensitively), or null. */
    public function retrieveByAddress(EmailAddress $address): ?MailboxPrincipal
    {
        $row = $this->lookup->find($address);

        return $row === null ? null : $this->principal($row);
    }

    /** Whether a guide row exists for $address, enabled or not. */
    public function exists(EmailAddress $address): bool
    {
        return $this->lookup->find($address) !== null;
    }

    public function retrieveByToken($identifier, $token): null
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    /** @param  array<string, mixed>  $credentials */
    public function retrieveByCredentials(#[\SensitiveParameter] array $credentials): ?MailboxPrincipal
    {
        $email = $credentials['email'] ?? null;
        if (! is_string($email)) {
            return null;
        }

        try {
            return $this->retrieveByAddress($this->normalizer->address($email));
        } catch (InvalidAddress) {
            return null;
        }
    }

    /**
     * Asks the mail server. A check that cannot be made counts as a wrong password: portal sign-in
     * fails closed when the auth socket is down.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function validateCredentials(Authenticatable $user, #[\SensitiveParameter] array $credentials): bool
    {
        $password = $credentials['password'] ?? null;
        if (! $user instanceof MailboxPrincipal || ! is_string($password)) {
            return false;
        }

        try {
            return $this->checker->verify($user->address, $password, request()->ip());
        } catch (DovecotAuthException|InvalidArgumentException) {
            return false;
        }
    }

    /**
     * The mail server owns mailbox hashes; the portal never rehashes them on sign-in.
     *
     * @param  array<string, mixed>  $credentials
     */
    public function rehashPasswordIfRequired(Authenticatable $user, #[\SensitiveParameter] array $credentials, bool $force = false): void {}

    private function principal(Mailbox $row): ?MailboxPrincipal
    {
        $address = $row->email;
        $hash = $row->getAttribute('password');
        $quota = $row->getAttribute('quota');
        $domain = Domain::query()->find($row->domain_id);

        if ($domain === null || ! is_string($hash) || DisabledMailbox::query()->whereKey($address)->exists()) {
            return null;
        }

        return new MailboxPrincipal(
            $address,
            $domain->id,
            $domain->name,
            QuotaFormat::effectiveBytes(is_numeric($quota) ? (int) $quota : 0),
            $hash,
        );
    }
}
