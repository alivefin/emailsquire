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

    /** Postfix's virtual_alias_expansion_limit default: addresses looked at per target, at most. */
    private const MAX_EXPANSION = 1000;

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

    /**
     * The targets whose mail Postfix would bring back to $owner: through their own rows (whatever else
     * they deliver to), further aliases, a domain's catch-all (`@domain`) for an address with no rows
     * that is no mailbox (the guide's email2email map finds a mailbox first), or a domain alias (a
     * catch-all to `@other`). Postfix marks an address "been here" only when it expands to itself, so a
     * mailbox that forwards without keeping a copy to such a target expands round the loop until
     * virtual_alias_recursion_limit and the mail is deferred; one that keeps a copy is marked and stops.
     *
     * Walks at most MAX_EXPANSION addresses per target, as Postfix gives up after that many too.
     *
     * @param  array<int, string>  $targets  lowercased, keyed by position in the request
     * @return array<int, string> the ones that come back, with their keys
     */
    public function deliveringBack(string $owner, array $targets, bool $lock = false): array
    {
        $owner = strtolower($owner);
        $back = [];
        foreach ($targets as $i => $target) {
            $seen = [$target => true];
            $frontier = [$target];
            while ($frontier !== [] && count($seen) < self::MAX_EXPANSION) {
                $next = [];
                foreach ($this->expand($frontier, $lock) as $destination) {
                    if ($destination === $owner) {
                        $back[$i] = $target;
                        break 2;
                    }
                    if (! isset($seen[$destination])) {
                        $seen[$destination] = true;
                        $next[] = $destination;
                    }
                }
                $frontier = $next;
            }
        }

        return $back;
    }

    /**
     * Where Postfix sends mail for each of $addresses next (lowercased): its rows' destinations; for an
     * address with none that is no mailbox, its domain's catch-all, where `@other` keeps the local part.
     *
     * @param  list<string>  $addresses  lowercased
     * @return list<string>
     */
    private function expand(array $addresses, bool $lock): array
    {
        $destinations = [];
        $withRows = [];
        foreach ($this->rows($lock)->whereRaw(self::SRC.' in ('.$this->marks($addresses).')', $addresses)->get(['source', 'destination']) as $row) {
            $withRows[strtolower(is_string($row->source ?? null) ? $row->source : '')] = true;
            $destinations[] = strtolower(is_string($row->destination ?? null) ? $row->destination : '');
        }

        $bare = array_values(array_filter($addresses, fn (string $a) => ! isset($withRows[$a]) && strpos($a, '@') > 0));
        if ($bare !== []) {
            $mailboxes = DB::table(GuideSchema::table('virtual_users'))
                ->whereRaw('convert(lower(email) using utf8mb4) collate utf8mb4_bin in ('.$this->marks($bare).')', $bare);
            foreach (($lock ? $mailboxes->sharedLock() : $mailboxes)->pluck('email') as $email) {
                $bare = array_values(array_diff($bare, [strtolower(is_string($email) ? $email : '')]));
            }
        }
        if ($bare !== []) {
            $catchAlls = [];
            foreach ($bare as $address) {
                $catchAlls['@'.substr($address, (int) strpos($address, '@') + 1)][] = substr($address, 0, (int) strpos($address, '@'));
            }
            $keys = array_keys($catchAlls);
            foreach ($this->rows($lock)->whereRaw(self::SRC.' in ('.$this->marks($keys).')', $keys)->get(['source', 'destination']) as $row) {
                $source = strtolower(is_string($row->source ?? null) ? $row->source : '');
                $destination = strtolower(is_string($row->destination ?? null) ? $row->destination : '');
                foreach ($catchAlls[$source] ?? [] as $local) {
                    $destinations[] = str_starts_with($destination, '@') ? $local.$destination : $destination;
                }
            }
        }

        return $destinations;
    }

    /**
     * One `?` placeholder per value, for an `in (…)` list.
     *
     * @param  list<string>  $values
     * @return literal-string
     */
    private function marks(array $values): string
    {
        return implode(',', array_fill(0, count($values), '?'));
    }

    private function rows(bool $lock): Builder
    {
        $query = DB::table(GuideSchema::table('virtual_aliases'));

        return $lock ? $query->sharedLock() : $query;
    }
}
