<?php

namespace App\MailServer;

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
            ? "{$a->address()} is blocked."
            : "{$a->address()} is blocked — reason: “{$blocked->reason}”.";
    }

    public function archivingMessage(EmailAddress $a): string
    {
        return "{$a->address()} was deleted recently and its mail is still being archived. Try again in a few minutes.";
    }

    public function assertCanCreateMailbox(EmailAddress $a): void
    {
        $address = $a->address();
        $this->assertNotBlocked($a);

        if ($this->isMailbox($address)) {
            throw new ClaimRefused('exists_mailbox', "{$address} already exists.", 'local');
        }
        if ($this->isForwarding($address)) {
            throw new ClaimRefused('exists_forwarding', "{$address} already forwards somewhere — remove that first.", 'local');
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
                    "{$address} is a mailbox. Confirm that its mail should be forwarded only.",
                    'redirect_mailbox',
                );
            }

            return;
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
