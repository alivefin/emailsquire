<?php

namespace App\MailServer\Queries;

use App\MailServer\Address\EmailAddress;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;

class MailboxLookup
{
    /**
     * Binary compare of the lowercased stored value: case-insensitive (legacy rows like Mixed@
     * stay reachable) but not accent-insensitive like the guide's utf8mb4_uca1400_ai_ci.
     */
    public function inDomain(Domain $domain, EmailAddress $address): ?Mailbox
    {
        return Mailbox::query()
            ->where('domain_id', $domain->id)
            ->whereRaw('convert(lower(email) using utf8mb4) collate utf8mb4_bin = ?', [$address->address()])
            ->first();
    }
}
