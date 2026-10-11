<?php

namespace App\MailServer\Repositories;

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\Address\DomainName;
use App\MailServer\Address\EmailAddress;
use App\MailServer\AddressClaims;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\ClaimRefused;
use App\MailServer\Exceptions\SelfServiceDisabled;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\MailServer\Queries\OwnForwarding;
use App\MailServer\SelfService;
use App\MailServer\SourceLock;
use App\Models\DisabledMailbox;
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
        private readonly OwnForwarding $own,
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
     * The mailbox owner's own forwarding (the portal): replaces every row of their address in one
     * transaction under its lock, as replace() does, keeping a copy (the row back to the mailbox) or not.
     * No targets removes every row, so the mailbox receives its mail as before. Audited as
     * `portal.forwarding_changed` with the console's `changes` shape; a request that changes nothing
     * writes nothing.
     *
     * Checked under the domain row's shared lock, so a switch changed meanwhile (SelfService::update()
     * locks that row) is seen: `forwarding` must be on (403), at most 10 targets, and a target on no
     * domain of this server needs `external_forwarding`. AddressClaims judges the source with the owner
     * as principal.
     *
     * @param  array<int, EmailAddress>  $targets  normalised, de-duplicated and never the owner's own address,
     *                                             keyed by their position in the request (for the error's field)
     * @return bool false when the mailbox is gone or disabled since the request signed in: nothing changed
     */
    public function replaceOwn(MailboxPrincipal $owner, array $targets, bool $keepCopy): bool
    {
        $address = strtolower($owner->address);
        $destinations = array_map(fn (EmailAddress $t) => $t->address(), $targets);
        if (in_array($address, $destinations, true) || count(array_unique($destinations)) !== count($destinations)) {
            throw new \InvalidArgumentException('Targets must be unique and never the mailbox itself.');
        }

        return SourceLock::run($address, fn () => DB::transaction(function () use ($owner, $address, $destinations, $keepCopy): bool {
            $domain = $this->ownerDomain($owner, 'forwarding');
            if ($domain === null) {
                return false;
            }

            if (count($destinations) > SelfService::MAX_TARGETS) {
                throw new ClaimRefused('too_many_targets', __('emailsquire.portal.too_many_targets', ['max' => SelfService::MAX_TARGETS]), 'targets');
            }
            if (! SelfService::for($domain)['external_forwarding']) {
                $this->assertOnServer($destinations);
            }

            $at = strrpos($address, '@');
            $this->claims->assertOwnerCanClaim($owner, new EmailAddress(
                substr($address, 0, (int) $at),
                new DomainName(substr($address, (int) $at + 1), substr($address, (int) $at + 1)),
            ), 'targets');

            // Lock order: domain row, mailbox rows, alias rows. The owner's row and the mailboxes we
            // forward to, so a concurrent delete of either waits for our commit and then removes our rows.
            Mailbox::query()->whereIn('email', [...array_values($destinations), $address])->sharedLock()->get();
            if (! $this->ownerIsLive($owner, false)) {
                return false;
            }

            $existing = Alias::query()->whereRaw(self::LOWER_BINARY.' = ?', [$address])->orderBy('id')->lockForUpdate()->get();
            [$oldTargets, $oldSelfCopy] = $this->split($existing, $address);
            $newTargets = array_values($destinations);
            $newSelfCopy = $keepCopy && $newTargets !== [];

            $sortedOld = $oldTargets;
            $sortedNew = $newTargets;
            sort($sortedOld);
            sort($sortedNew);
            if ($sortedOld === $sortedNew && $oldSelfCopy === $newSelfCopy && $existing->count() === count($oldTargets) + ($oldSelfCopy ? 1 : 0)) {
                return true; // nothing to change
            }

            Alias::query()->whereRaw(self::LOWER_BINARY.' = ?', [$address])->delete();
            $rows = array_map(fn (string $t) => ['domain_id' => $domain->id, 'source' => $address, 'destination' => $t], $newTargets);
            if ($newSelfCopy) {
                $rows[] = ['domain_id' => $domain->id, 'source' => $address, 'destination' => $address];
            }
            if ($rows !== []) {
                Alias::query()->insert($rows);
            }

            $this->audit->record('portal.forwarding_changed', 'forwarding', $address, $domain->name, [
                'old' => $oldTargets,
                'new' => $newTargets,
                'keep_copy' => ['old' => $oldSelfCopy, 'new' => $newSelfCopy],
            ]);

            return true;
        }));
    }

    /**
     * Adds the alias $source -> the owner's mailbox (the portal), under the source's lock. `aliases` must
     * be on (403); AddressClaims judges the source with the owner as principal; the owner's own address
     * and a source that already has rows (even ones to the owner) are refused with the same
     * `address_unavailable`; at most 20 aliases.
     * The owner's mailbox row is locked FOR UPDATE, so two aliases added at once are counted one after
     * the other. Audited as `portal.alias_created`.
     *
     * @return bool false when the mailbox is gone or disabled since the request signed in: nothing changed
     */
    public function addOwnAlias(MailboxPrincipal $owner, EmailAddress $source): bool
    {
        $own = strtolower($owner->address);
        $address = $source->address();
        if ($address === $own) {
            throw $this->claims->unavailable();
        }

        return SourceLock::run($address, fn () => DB::transaction(function () use ($owner, $source, $own, $address): bool {
            $domain = $this->ownerDomain($owner, 'aliases');
            if ($domain === null) {
                return false;
            }
            $this->claims->assertOwnerCanClaim($owner, $source);

            if (! $this->ownerIsLive($owner, true)) {
                return false;
            }

            if (Alias::query()->whereRaw(self::LOWER_BINARY.' = ?', [$address])->lockForUpdate()->exists()) {
                throw $this->claims->unavailable();
            }
            if (count($this->own->aliases($owner, true)) >= SelfService::MAX_ALIASES) {
                throw new ClaimRefused('too_many_aliases', __('emailsquire.portal.too_many_aliases', ['max' => SelfService::MAX_ALIASES]), 'local');
            }

            Alias::query()->insert(['domain_id' => $domain->id, 'source' => $address, 'destination' => $own]);
            $this->audit->record('portal.alias_created', 'forwarding', $address, $domain->name, ['destination' => $own]);

            return true;
        }));
    }

    /**
     * Removes the owner's alias $source (the portal), under the source's lock: only what the list shows
     * (OwnForwarding::aliases()), a source with a row on their domain whose every row, on any domain_id,
     * delivers to them, that is neither their own address nor a mailbox; anything else is a 404, so the
     * owner learns nothing about it. `aliases` must be on (403). Audited as
     * `portal.alias_deleted`.
     *
     * @return bool false when the mailbox is gone or disabled since the request signed in: nothing changed
     */
    public function removeOwnAlias(MailboxPrincipal $owner, EmailAddress $source): bool
    {
        $own = strtolower($owner->address);
        $address = $source->address();

        $result = SourceLock::run($address, fn () => DB::transaction(function () use ($owner, $source, $own, $address): string {
            $domain = $this->ownerDomain($owner, 'aliases');
            if ($domain === null) {
                return 'gone';
            }
            if (! $this->ownerIsLive($owner, true)) {
                return 'gone';
            }

            $query = Alias::query()->whereRaw(self::LOWER_BINARY.' = ?', [$address]);
            $existing = (clone $query)->orderBy('id')->lockForUpdate()->get();
            $foreign = $existing->contains(fn (Alias $row) => strtolower(is_string($row->getAttribute('destination')) ? $row->getAttribute('destination') : '') !== $own);
            $onOwnDomain = $existing->contains(fn (Alias $row) => $this->int($row->getAttribute('domain_id')) === $domain->id);
            if (! $onOwnDomain || $foreign || $address === $own || $source->local === ''
                || $source->domain->ascii !== strtolower($domain->name)
                || Mailbox::query()->where('email', $address)->exists()) {
                return 'missing';
            }

            $query->delete();
            $this->audit->record('portal.alias_deleted', 'forwarding', $address, $domain->name, ['destination' => $own]);

            return 'removed';
        }));

        if ($result === 'missing') {
            abort(404);
        }

        return $result === 'removed';
    }

    /**
     * The owner's domain row, share-locked (the first lock after the source's), with its $switch on.
     * Null when the domain is gone, and with it the mailbox.
     *
     * @param  'forwarding'|'aliases'  $switch
     */
    private function ownerDomain(MailboxPrincipal $owner, string $switch): ?Domain
    {
        $domain = Domain::query()->whereKey($owner->domainId)->sharedLock()->first();
        if ($domain === null) {
            return null;
        }
        if (! SelfService::for($domain)[$switch]) {
            throw new SelfServiceDisabled($switch);
        }

        return $domain;
    }

    /**
     * Whether the owner's mailbox row still exists with the session's spelling (the guide collation
     * folds case) and is not disabled; with $forUpdate its row is locked FOR UPDATE.
     */
    private function ownerIsLive(MailboxPrincipal $owner, bool $forUpdate): bool
    {
        $query = Mailbox::query()->where('email', $owner->address);
        $live = ($forUpdate ? $query->lockForUpdate() : $query->sharedLock())->first();

        return $live !== null && $live->email === $owner->address
            && ! DisabledMailbox::query()->where('mailbox_email', $live->email)->exists();
    }

    /**
     * Refuses the first target on no domain of this server (`external_forwarding_disabled`, on that
     * target's field).
     *
     * @param  array<int, string>  $destinations  keyed by their position in the request
     */
    private function assertOnServer(array $destinations): void
    {
        $domains = [];
        foreach ($destinations as $destination) {
            $domains[] = substr($destination, (int) strrpos($destination, '@') + 1);
        }
        $onServer = [];
        foreach (Domain::query()->whereIn('name', array_values(array_unique($domains)))->pluck('name') as $name) {
            $onServer[strtolower(is_string($name) ? $name : '')] = true;
        }

        foreach ($destinations as $i => $destination) {
            if (! isset($onServer[substr($destination, (int) strrpos($destination, '@') + 1)])) {
                throw new ClaimRefused(
                    'external_forwarding_disabled',
                    __('emailsquire.portal.external_forwarding_disabled', ['address' => $destination]),
                    "targets.{$i}",
                );
            }
        }
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
