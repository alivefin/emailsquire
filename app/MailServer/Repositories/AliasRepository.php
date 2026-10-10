<?php

namespace App\MailServer\Repositories;

use App\MailServer\Address\EmailAddress;
use App\MailServer\AddressClaims;
use App\MailServer\AuditLog;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\SourceLock;
use Illuminate\Support\Facades\DB;

/** Writes forwarding: virtual_aliases rows grouped by source, which Postfix reads live. */
class AliasRepository
{
    /** Legacy rows may be mixed case, so a source is matched lowercased and binary on both sides. */
    private const LOWER_BINARY = 'convert(lower(source) using utf8mb4) collate utf8mb4_bin';

    public function __construct(
        private readonly AuditLog $audit,
        private readonly AddressClaims $claims,
    ) {}

    /**
     * Replaces the whole target set of a source in one transaction, under the source's lock.
     *
     * @param  list<EmailAddress>  $targets  normalised and de-duplicated, 1 to 50
     */
    public function replace(Domain $d, EmailAddress $source, array $targets, bool $keepCopy, bool $redirectMailbox): void
    {
        $address = $source->address();
        $destinations = array_map(fn (EmailAddress $t) => $t->address(), $targets);

        SourceLock::run($address, fn () => DB::transaction(function () use ($d, $source, $address, $destinations, $keepCopy, $redirectMailbox) {
            $this->claims->assertCanForward($source, $keepCopy, $redirectMailbox);

            // Shared-lock the mailboxes we forward to before touching alias rows: a concurrent mailbox
            // delete then waits for our commit and removes our rows, instead of leaving a forward to
            // a deleted address. (Locking after the delete would invite a deadlock with that path.)
            Mailbox::query()->whereIn('email', $destinations)->sharedLock()->get();

            $isMailbox = Mailbox::query()->where('email', $address)->exists();
            $selfCopy = $keepCopy && $isMailbox;

            $existing = Alias::query()->whereRaw(self::LOWER_BINARY.' = ?', [$address])->orderBy('id')->lockForUpdate()->get();
            $oldTargets = [];
            $oldSelfCopy = false;
            foreach ($existing as $row) {
                $destination = strtolower(is_string($row->getAttribute('destination')) ? $row->getAttribute('destination') : '');
                if ($destination === $address) {
                    $oldSelfCopy = true;
                } else {
                    $oldTargets[] = $destination;
                }
            }

            Alias::query()->whereRaw(self::LOWER_BINARY.' = ?', [$address])->delete();

            $rows = array_map(fn (string $t) => ['domain_id' => $d->id, 'source' => $address, 'destination' => $t], $destinations);
            if ($selfCopy) {
                $rows[] = ['domain_id' => $d->id, 'source' => $address, 'destination' => $address];
            }
            Alias::query()->insert($rows);

            $this->audit->record('forwarding.replace', 'forwarding', $address, $d->name, [
                'old' => $oldTargets,
                'new' => $destinations,
                'keep_copy' => ['old' => $oldSelfCopy, 'new' => $selfCopy],
            ]);
        }));
    }

    /** @return int rows deleted; 404 when the source had none */
    public function remove(Domain $d, string $local): int
    {
        $address = strtolower($local.'@'.$d->name);

        $removed = SourceLock::run($address, fn () => DB::transaction(function () use ($d, $address) {
            $query = Alias::query()->where('domain_id', $d->id)->whereRaw(self::LOWER_BINARY.' = ?', [$address]);
            $count = (clone $query)->lockForUpdate()->count();
            if ($count === 0) {
                return 0;
            }

            $query->delete();
            $this->audit->record('forwarding.delete', 'forwarding', $address, $d->name, ['removed' => $count]);

            return $count;
        }));

        if ($removed === 0) {
            abort(404);
        }

        return $removed;
    }
}
