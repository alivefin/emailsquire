<?php

namespace App\MailServer\Queries;

use App\Auth\Mailbox\MailboxPrincipal;
use App\MailServer\GuideSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Read side of the portal's forwarding and aliases: only what belongs to one mailbox.
 *
 * - Its forwarding is the rows whose source is its own address; a row back to itself is the kept copy.
 * - Its aliases are the sources on its own domain whose every row delivers to it, leaving out its own
 *   address, catch-alls (`@domain`) and other mailboxes (a mailbox redirected to it is still that
 *   mailbox's own forwarding, backend plan §6 rules 3 and 5).
 *
 * Every compare is lowercased and binary, as in ForwardingList, so legacy mixed-case rows count. With
 * $lock the rows are read with a shared lock, for a writer that must see the latest committed rows.
 */
class OwnForwarding
{
    private const SRC = 'convert(lower(source) using utf8mb4) collate utf8mb4_bin';

    private const DEST = 'convert(lower(destination) using utf8mb4) collate utf8mb4_bin';

    /** @return array{targets: list<string>, keep_copy: bool} targets lowercased, in row order */
    public function forwarding(MailboxPrincipal $owner, bool $lock = false): array
    {
        $own = strtolower($owner->address);
        $targets = [];
        $keepCopy = false;
        foreach ($this->rows($lock)->whereRaw(self::SRC.' = ?', [$own])->orderBy('id')->pluck('destination') as $destination) {
            $destination = strtolower(is_string($destination) ? $destination : '');
            if ($destination === $own) {
                $keepCopy = true;
            } elseif (! in_array($destination, $targets, true)) {
                $targets[] = $destination;
            }
        }

        return ['targets' => $targets, 'keep_copy' => $keepCopy];
    }

    /** @return list<string> the alias addresses, lowercased and sorted */
    public function aliases(MailboxPrincipal $owner, bool $lock = false): array
    {
        $own = strtolower($owner->address);
        $suffix = '@'.strtolower($owner->domainName);

        $candidates = [];
        foreach ($this->rows($lock)->where('domain_id', $owner->domainId)->whereRaw(self::DEST.' = ?', [$own])->pluck('source') as $source) {
            $source = strtolower(is_string($source) ? $source : '');
            $local = substr($source, 0, -strlen($suffix));
            if (str_ends_with($source, $suffix) && $local !== '' && ! str_contains($local, '@') && $source !== $own) {
                $candidates[$source] = true;
            }
        }
        if ($candidates === []) {
            return [];
        }

        $sources = array_keys($candidates);
        $in = implode(',', array_fill(0, count($sources), '?'));
        // A source with any other destination is not (only) the owner's.
        foreach ($this->rows($lock)->whereRaw(self::SRC." in ({$in})", $sources)->get(['source', 'destination']) as $row) {
            $source = strtolower(is_string($row->source ?? null) ? $row->source : '');
            $destination = strtolower(is_string($row->destination ?? null) ? $row->destination : '');
            if ($destination !== $own) {
                unset($candidates[$source]);
            }
        }

        $mailboxes = DB::table(GuideSchema::table('virtual_users'))
            ->whereRaw('convert(lower(email) using utf8mb4) collate utf8mb4_bin in ('.$in.')', $sources)
            ->pluck('email');
        foreach ($mailboxes as $email) {
            unset($candidates[strtolower(is_string($email) ? $email : '')]);
        }

        $aliases = array_map('strval', array_keys($candidates));
        sort($aliases);

        return $aliases;
    }

    private function rows(bool $lock): Builder
    {
        $query = DB::table(GuideSchema::table('virtual_aliases'));

        return $lock ? $query->sharedLock() : $query;
    }
}
