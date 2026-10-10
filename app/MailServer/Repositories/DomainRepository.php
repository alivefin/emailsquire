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
    ) {}

    public function create(DomainName $name): Domain
    {
        return DB::transaction(function () use ($name) {
            // virtual_domains may lack its unique key (restored data), so check explicitly.
            $exists = Domain::query()->where('name', $name->ascii)->lockForUpdate()->exists();
            if ($exists) {
                throw new Conflict('domain_exists', "{$name->ascii} is already on this server.");
            }

            $domain = new Domain;
            $domain->forceFill(['name' => $name->ascii])->save();

            $this->audit->record('domain.create', 'domain', $name->ascii, $name->ascii);

            return $domain;
        });
    }

    /**
     * Removes the domain and everything hanging off it. Does not rely on ON DELETE CASCADE:
     * restored guide data may not have it.
     *
     * @return array{mailboxes: int, forwarding: int}
     */
    public function delete(Domain $domain): array
    {
        $addresses = [];

        $counts = DB::transaction(function () use ($domain, &$addresses) {
            $id = $domain->id;
            $name = $domain->name;

            $forwarding = Alias::query()->where('domain_id', $id)
                ->whereColumn('destination', '<>', 'source')
                ->distinct()->count('source');
            $addresses = Mailbox::query()->where('domain_id', $id)->orderBy('email')->get()->map(fn (Mailbox $m) => $m->email)->values()->all();

            Alias::query()->where('domain_id', $id)->delete();
            Mailbox::query()->where('domain_id', $id)->delete();
            Domain::query()->whereKey($id)->delete();

            MailboxSetting::query()->where('domain_id', $id)->delete();
            DisabledMailbox::query()->where('domain_id', $id)->delete();
            PinnedDomain::query()->where('domain_id', $id)->delete();
            foreach (array_chunk($addresses, 500) as $chunk) {
                MailboxUsage::query()->whereIn('email', $chunk)->delete();
            }

            $domainName = new DomainName($name, $name);
            foreach ($addresses as $address) {
                [$local] = explode('@', $address, 2);
                $this->tasks->queueArchive(new EmailAddress($local, $domainName));
            }

            $counts = ['mailboxes' => count($addresses), 'forwarding' => $forwarding];
            $this->audit->record('domain.delete', 'domain', $name, $name, $counts);

            return $counts;
        });

        $this->sessions->kick(...$addresses);

        return $counts;
    }
}
