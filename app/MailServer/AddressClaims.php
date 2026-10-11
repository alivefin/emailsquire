<?php

namespace App\MailServer;

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Address\EmailAddress;
use App\MailServer\Exceptions\ClaimRefused;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Mailbox;
use App\MailServer\Repositories\BlockedAddressRepository;

/** The rule gate for every write that claims an address: blocked, taken, or still being archived. */
class AddressClaims
{
    public function __construct(
        private readonly BlockedAddressRepository $blocked,
        private readonly HelperTasks $tasks,
    ) {}

    /** @return 'available'|'mailbox'|'forwarding'|'blocked'|'archiving' */
    public function status(EmailAddress $a): string
    {
        $address = $a->address();

        return match (true) {
            $this->blocked->find($address) !== null => 'blocked',
            $this->isMailbox($address) => 'mailbox',
            $this->isForwarding($address) => 'forwarding',
            $this->tasks->archivePending($address) => 'archiving',
            default => 'available',
        };
    }

    public function blockedMessage(EmailAddress $a): ?string
    {
        $blocked = $this->blocked->find($a->address());
        if ($blocked === null) {
            return null;
        }

        return $blocked->reason === null || $blocked->reason === ''
            ? __('emailsquire.claims.blocked', ['address' => $a->address()])
            : __('emailsquire.claims.blocked_reason', ['address' => $a->address(), 'reason' => $blocked->reason]);
    }

    public function archivingMessage(EmailAddress $a): string
    {
        return __('emailsquire.claims.archiving', ['address' => $a->address()]);
    }

    public function assertCanCreateMailbox(EmailAddress $a): void
    {
        $address = $a->address();
        $this->assertNotBlocked($a);

        if ($this->isMailbox($address)) {
            throw new ClaimRefused('exists_mailbox', __('emailsquire.claims.exists_mailbox', ['address' => $address]), 'local');
        }
        if ($this->isForwarding($address)) {
            throw new ClaimRefused('exists_forwarding', __('emailsquire.claims.exists_forwarding', ['address' => $address]), 'local');
        }
        $this->assertNotArchiving($a);
    }

    public function assertCanForward(EmailAddress $source, bool $keepCopy, bool $redirectMailbox): void
    {
        $address = $source->address();
        $this->assertNotBlocked($source);

        if ($this->isMailbox($address)) {
            if (! $keepCopy && ! $redirectMailbox) {
                throw new ClaimRefused(
                    'redirect_not_confirmed',
                    __('emailsquire.claims.redirect_not_confirmed', ['address' => $address]),
                    'redirect_mailbox',
                );
            }

            return;
        }

        $this->assertNotArchiving($source);
    }

    /**
     * Backend plan §6 address rules 1-5 with a mailbox owner (the portal) as principal; $source is the
     * owner's own address (their forwarding) or an alias they want. The console's assertCanForward()
     * stays as it is: a manager may redirect a mailbox, an owner never redirects anyone else's.
     *
     * 1. The source is on the owner's own domain.
     * 5. It is not a catch-all (`@domain`): those are for admins and managers.
     * 2. It is not blocked, the owner's own address included.
     * 3. A mailbox may be claimed only by its own owner.
     * 4. Rows already on the source must all deliver to the owner; anything else is someone else's.
     *
     * A source still being archived is a 409, as for the console.
     */
    public function assertOwnerCanClaim(MailboxPrincipal $owner, EmailAddress $source): void
    {
        $address = $source->address();
        $own = strtolower($owner->address);

        if ($source->domain->ascii !== strtolower($owner->domainName)) {
            throw new ClaimRefused('not_own_domain', __('emailsquire.claims.not_own_domain', ['address' => $address]), 'local');
        }
        if ($source->local === '') {
            throw new ClaimRefused('catch_all', __('emailsquire.claims.catch_all'), 'local');
        }
        $this->assertNotBlocked($source);

        if ($address === $own) {
            return;
        }
        if ($this->isMailbox($address)) {
            throw new ClaimRefused('exists_mailbox', __('emailsquire.claims.exists_mailbox', ['address' => $address]), 'local');
        }
        foreach (Alias::query()->where('source', $address)->pluck('destination') as $destination) {
            if (! is_string($destination) || strtolower($destination) !== $own) {
                throw new ClaimRefused('exists_forwarding', __('emailsquire.claims.exists_forwarding', ['address' => $address]), 'local');
            }
        }
        $this->assertNotArchiving($source);
    }

    private function assertNotBlocked(EmailAddress $a): void
    {
        $message = $this->blockedMessage($a);
        if ($message !== null) {
            throw new ClaimRefused('blocked', $message, 'local');
        }
    }

    private function assertNotArchiving(EmailAddress $a): void
    {
        if ($this->tasks->archivePending($a->address())) {
            throw new Conflict('archiving', $this->archivingMessage($a));
        }
    }

    private function isMailbox(string $address): bool
    {
        return Mailbox::query()->where('email', $address)->exists();
    }

    private function isForwarding(string $address): bool
    {
        return Alias::query()->where('source', $address)->exists();
    }
}
