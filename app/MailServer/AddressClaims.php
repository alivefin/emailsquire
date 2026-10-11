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
        private readonly CatchAllSupport $catchAll,
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
     * Backend plan §6 rule 5 for the console: a catch-all (`@domain`) only while Postfix's alias maps
     * include email2email, so that existing mailboxes keep their mail (409 `catch_all_unsupported`
     * otherwise). The route's policy already limits it to admins and managers; owners never get here
     * (assertOwnerCanClaim() refuses an empty local part).
     */
    public function assertCanSetCatchAll(): void
    {
        $this->catchAll->assertSupported();
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
     * For an alias also: its local part is not reserved (`emailsquire.owner_reserved_locals`, such as
     * postmaster), nothing is still being archived there, and it would not take mail away from the
     * domain's catch-all unless that catch-all already delivers only to the owner.
     *
     * Every refusal is the same 422 `address_unavailable` on $field: an owner learns nothing about an
     * address they can't take (not whether it is a mailbox, a forward or blocked, nor an admin's reason).
     */
    public function assertOwnerCanClaim(MailboxPrincipal $owner, EmailAddress $source, string $field = 'local'): void
    {
        $address = $source->address();
        $own = strtolower($owner->address);
        $domain = strtolower($owner->domainName);

        if ($source->domain->ascii !== $domain || $source->local === '' || $this->blocked->find($address) !== null) {
            throw $this->unavailable($field);
        }
        if ($address === $own) {
            return;
        }

        // The catch-all rows are read without a lock, under the domain row's shared lock that a catch-all
        // write shares too. Locking them here would take alias rows before the owner's mailbox row
        // (ownerIsLive()), against the lock order, and could deadlock with a catch-all to this mailbox.
        // The race left is harmless: an alias added while a catch-all is being set ends as if the alias
        // had come first, which is allowed (setting a catch-all never checks aliases); the alias takes
        // no mail the catch-all had already been getting.
        if ($this->isReservedForOwners($source->local)
            || $this->isMailbox($address)
            || ! $this->deliversOnlyTo($address, $own)
            || ! $this->deliversOnlyTo('@'.$domain, $own)
            || $this->tasks->archivePending($address)) {
            throw $this->unavailable($field);
        }
    }

    /** The refusal every owner-facing claim check gives (assertOwnerCanClaim()). */
    public function unavailable(string $field = 'local'): ClaimRefused
    {
        return new ClaimRefused('address_unavailable', trans()->string('emailsquire.portal.address_unavailable'), $field);
    }

    /** A local part only admins and managers may give out (`emailsquire.owner_reserved_locals`), compared case-insensitively. */
    private function isReservedForOwners(string $local): bool
    {
        $reserved = config('emailsquire.owner_reserved_locals');
        foreach (is_array($reserved) ? $reserved : [] as $name) {
            if (is_string($name) && strtolower($name) === strtolower($local)) {
                return true;
            }
        }

        return false;
    }

    /** Whether every row of $source (none counts) delivers to $address. */
    private function deliversOnlyTo(string $source, string $address): bool
    {
        foreach (Alias::query()->where('source', $source)->pluck('destination') as $destination) {
            if (! is_string($destination) || strtolower($destination) !== $address) {
                return false;
            }
        }

        return true;
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
