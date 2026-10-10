<?php

namespace App\MailServer\Repositories;

use App\MailServer\Address\EmailAddress;
use App\MailServer\AddressClaims;
use App\MailServer\AuditLog;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\SourceLock;
use Illuminate\Database\Eloquent\Builder;
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
            // Lock order: domain row, then mailbox rows, then alias rows. A domain delete holds the row
            // FOR UPDATE, so this waits for it and then 404s instead of writing rows under a deleted domain.
            Domain::query()->whereKey($d->getKey())->sharedLock()->firstOrFail();

            $this->claims->assertCanForward($source, $keepCopy, $redirectMailbox);

            // Shared-lock the mailboxes we forward to before touching alias rows: a concurrent mailbox
            // delete then waits for our commit and removes our rows, instead of leaving a forward to
            // a deleted address. (Locking after the delete would invite a deadlock with that path.)
            Mailbox::query()->whereIn('email', $destinations)->sharedLock()->get();

            $isMailbox = Mailbox::query()->where('email', $address)->exists();
            $selfCopy = $keepCopy && $isMailbox;

            $existing = Alias::query()->whereRaw(self::LOWER_BINARY.' = ?', [$address])->orderBy('id')->lockForUpdate()->get();
            [$oldTargets, $oldSelfCopy] = $this->split($existing, $address);

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
            $existing = (clone $query)->orderBy('id')->lockForUpdate()->get();
            if ($existing->isEmpty()) {
                return 0;
            }
            [$oldTargets, $oldSelfCopy] = $this->split($existing, $address);

            $query->delete();
            $this->audit->record('forwarding.delete', 'forwarding', $address, $d->name, [
                'removed' => $existing->count(),
                'old' => $oldTargets,
                'new' => [],
                'keep_copy' => ['old' => $oldSelfCopy, 'new' => false],
            ]);

            return $existing->count();
        }));

        if ($removed === 0) {
            abort(404);
        }

        return $removed;
    }

    /**
     * Removes forwarding rows whose destination is going away (a deleted mailbox or domain), inside the
     * caller's transaction. Each affected source not on $ownDomainId gets its own audit row under its own
     * domain, so that domain's people see what changed: `forwarding.replace` when it keeps other targets,
     * `forwarding.delete` when it keeps none (a keep-copy row stays as it was). Sources on $ownDomainId are
     * left to the caller's own audit row.
     *
     * The rows, and every row of each affected source, are read FOR UPDATE. Known limit: this takes no
     * SourceLock on those sources (nesting them inside the caller's lock could deadlock), so a forwarding
     * change racing on the same source is serialised only by the row locks.
     *
     * @param  Builder<Alias>  $pointing  the rows to remove
     * @return array<string, int> each affected source (lowercased) => its domain_id
     */
    public function removePointing(Builder $pointing, ?int $ownDomainId): array
    {
        $rows = (clone $pointing)->orderBy('id')->lockForUpdate()->get();
        if ($rows->isEmpty()) {
            return [];
        }

        $sources = [];
        $removedIds = [];
        foreach ($rows as $row) {
            $sources[$this->lower($row->getAttribute('source'))] = $this->int($row->getAttribute('domain_id'));
            $removedIds[] = $this->int($row->getKey());
        }

        $foreign = array_filter($sources, fn (int $domainId) => $domainId !== $ownDomainId);
        $before = [];
        if ($foreign !== []) {
            $keys = array_keys($foreign);
            $all = Alias::query()
                ->whereRaw(self::LOWER_BINARY.' in ('.implode(',', array_fill(0, count($keys), '?')).')', $keys)
                ->orderBy('id')->lockForUpdate()->get();
            foreach ($all as $row) {
                $before[$this->lower($row->getAttribute('source'))][] = $row;
            }
        }

        Alias::query()->whereIn('id', $removedIds)->delete();

        $names = Domain::query()->whereIn('id', array_values(array_unique($foreign)))->pluck('name', 'id');
        foreach ($foreign as $source => $domainId) {
            $old = $before[$source] ?? [];
            $kept = array_values(array_filter($old, fn (Alias $r) => ! in_array($this->int($r->getKey()), $removedIds, true)));
            [$oldTargets, $oldSelfCopy] = $this->split($old, $source);
            [$newTargets, $newSelfCopy] = $this->split($kept, $source);
            $name = $names[$domainId] ?? null;
            $at = strrpos($source, '@');
            $domain = is_string($name) ? strtolower($name) : ($at === false ? null : substr($source, $at + 1));
            $keepCopy = ['old' => $oldSelfCopy, 'new' => $newSelfCopy];

            if ($newTargets === []) {
                $this->audit->record('forwarding.delete', 'forwarding', $source, $domain, [
                    'removed' => count($old) - count($kept), 'old' => $oldTargets, 'new' => [], 'keep_copy' => $keepCopy,
                ]);
            } else {
                $this->audit->record('forwarding.replace', 'forwarding', $source, $domain, [
                    'old' => $oldTargets, 'new' => $newTargets, 'keep_copy' => $keepCopy,
                ]);
            }
        }

        return $sources;
    }

    private function lower(mixed $value): string
    {
        return strtolower(is_scalar($value) ? (string) $value : '');
    }

    private function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Splits a source's rows into its forwarding targets (lowercased) and whether it keeps a copy.
     *
     * @param  iterable<Alias>  $rows
     * @return array{0: list<string>, 1: bool}
     */
    private function split(iterable $rows, string $address): array
    {
        $targets = [];
        $selfCopy = false;
        foreach ($rows as $row) {
            $destination = strtolower(is_string($row->getAttribute('destination')) ? $row->getAttribute('destination') : '');
            if ($destination === $address) {
                $selfCopy = true;
            } else {
                $targets[] = $destination;
            }
        }

        return [$targets, $selfCopy];
    }
}
