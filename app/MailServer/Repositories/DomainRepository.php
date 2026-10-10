<?php

namespace App\MailServer\Repositories;

use App\MailServer\Address\DomainName;
use App\MailServer\Address\EmailAddress;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\HelperTasks;
use App\MailServer\MailboxSessions;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\DisabledMailbox;
use App\Models\DomainGrant;
use App\Models\DomainSetting;
use App\Models\MailboxSetting;
use App\Models\MailboxUsage;
use App\Models\PinnedDomain;
use Illuminate\Support\Facades\DB;

class DomainRepository
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly HelperTasks $tasks,
        private readonly MailboxSessions $sessions,
        private readonly AliasRepository $aliases,
    ) {}

    public function create(DomainName $name): Domain
    {
        return DB::transaction(function () use ($name) {
            // virtual_domains may lack its unique key (restored data), so check explicitly.
            $exists = Domain::query()->where('name', $name->ascii)->lockForUpdate()->exists();
            if ($exists) {
                throw new Conflict('domain_exists', __('emailsquire.domain.exists', ['name' => $name->ascii]));
            }

            $domain = new Domain;
            $domain->forceFill(['name' => $name->ascii])->save();

            $this->audit->record('domain.create', 'domain', $name->ascii, $name->ascii);

            return $domain;
        });
    }

    /**
     * Removes the domain and everything hanging off it, plus forwarding from other domains into it.
     * Does not rely on ON DELETE CASCADE: restored guide data may not have it.
     *
     * Lock order, here and in every writer: domain row, then mailbox rows, then alias rows. Writers
     * that add rows under a domain lock its row first (shared, or FOR UPDATE where the domain's limits
     * are counted), so they wait for this delete to commit and then find the domain gone (404) instead
     * of leaving orphan rows.
     *
     * @return array{mailboxes: int, forwarding: int, incoming_forwarding_removed: int}
     */
    public function delete(Domain $domain): array
    {
        $addresses = [];

        $counts = DB::transaction(function () use ($domain, &$addresses) {
            $live = Domain::query()->whereKey($domain->getKey())->lockForUpdate()->firstOrFail();
            $id = $live->id;
            $name = $live->name;

            $addresses = Mailbox::query()->where('domain_id', $id)->orderBy('email')->lockForUpdate()->get()
                ->map(fn (Mailbox $m) => $m->email)->values()->all();

            $forwarding = Alias::query()->where('domain_id', $id)
                ->whereColumn('destination', '<>', 'source')
                ->distinct()->count('source');

            // Forwarding of other domains into this one would point at addresses that no longer exist.
            // Guide columns may be utf8mb3: convert before the binary collation, compare lowercased.
            // Each source gets its own audit row under its own domain (AliasRepository::removePointing).
            $incoming = Alias::query()->where('domain_id', '<>', $id)
                ->whereRaw("convert(lower(substring_index(destination, '@', -1)) using utf8mb4) collate utf8mb4_bin = ?", [strtolower($name)]);
            $incomingSources = count($this->aliases->removePointing($incoming, $id));

            Alias::query()->where('domain_id', $id)->delete();
            Mailbox::query()->where('domain_id', $id)->delete();
            Domain::query()->whereKey($id)->delete();

            MailboxSetting::query()->where('domain_id', $id)->delete();
            DisabledMailbox::query()->where('domain_id', $id)->delete();
            PinnedDomain::query()->where('domain_id', $id)->delete();
            // Nobody keeps a role on a domain that is gone, nor on a later one that might reuse its id.
            DomainGrant::query()->where('domain_id', $id)->delete();
            DomainSetting::query()->where('domain_id', $id)->delete();
            foreach (array_chunk($addresses, 500) as $chunk) {
                MailboxUsage::query()->whereIn('email', $chunk)->delete();
            }

            foreach ($addresses as $address) {
                // The maildir belongs to the stored address in its stored spelling, whatever domain_id says:
                // Dovecot's home is built from the email the userdb returns, unchanged.
                $at = strrpos($address, '@');
                $local = $at === false ? $address : substr($address, 0, $at);
                $host = $at === false ? $name : substr($address, $at + 1);
                $this->tasks->queueArchive(new EmailAddress($local, new DomainName($host, $host)));
            }

            $counts = ['mailboxes' => count($addresses), 'forwarding' => $forwarding, 'incoming_forwarding_removed' => $incomingSources];
            $this->audit->record('domain.delete', 'domain', $name, $name, $counts);

            return $counts;
        });

        $this->sessions->kick(...$addresses);

        return $counts;
    }
}
