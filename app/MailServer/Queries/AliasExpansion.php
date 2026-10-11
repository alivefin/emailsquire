<?php

namespace App\MailServer\Queries;

use App\MailServer\GuideSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How Postfix expands an address through the guide's virtual_aliases, one step at a time:
 *
 * - an address with rows goes to their destinations (an alias map matches before email2email);
 * - one without rows that is a mailbox is delivered (email2email answers it with itself);
 * - one with neither goes to its domain's catch-all (`@domain`), where a destination `@other` keeps the
 *   local part (a domain alias); without a catch-all it goes nowhere here.
 *
 * Postfix marks an address "been here" only when it expands to itself (a row back to itself, the kept
 * copy), so only such an address, or a mailbox without rows, stops a loop. Shared by OwnForwarding (the
 * portal's loop check) and the console's catch-all check (catchAllProblems()).
 *
 * Every compare is lowercased and binary, as in ForwardingList, so legacy mixed-case rows count. With
 * $lock the rows are read with a shared lock.
 */
class AliasExpansion
{
    /** Postfix's virtual_alias_expansion_limit default: addresses looked at per target, at most. */
    public const MAX_EXPANSION = 1000;

    private const SRC = 'convert(lower(source) using utf8mb4) collate utf8mb4_bin';

    /**
     * One expansion step for each of $addresses (lowercased): how Postfix treats it and where it goes.
     * $override gives a source's destinations instead of its rows (a write not made yet).
     *
     * @param  list<string>  $addresses  lowercased
     * @param  array<string, list<string>>  $override  lowercased source => lowercased destinations
     * @return array<string, array{via: 'rows'|'mailbox'|'catch_all'|'none', to: list<string>}>
     */
    public function step(array $addresses, bool $lock, array $override = []): array
    {
        $out = [];
        foreach ($addresses as $address) {
            $out[$address] = isset($override[$address]) ? ['via' => 'rows', 'to' => $override[$address]] : ['via' => 'none', 'to' => []];
        }

        $fromRows = array_values(array_filter($addresses, fn (string $a) => ! isset($override[$a])));
        if ($fromRows !== []) {
            foreach ($this->rows($lock)->whereRaw(self::SRC.' in ('.$this->marks($fromRows).')', $fromRows)->orderBy('id')->get(['source', 'destination']) as $row) {
                $source = $this->lower($row->source ?? null);
                if (isset($out[$source])) {
                    $out[$source]['via'] = 'rows';
                    $out[$source]['to'][] = $this->lower($row->destination ?? null);
                }
            }
        }

        $bare = array_values(array_filter($addresses, fn (string $a) => $out[$a]['via'] === 'none' && strpos($a, '@') > 0));
        if ($bare !== []) {
            $mailboxes = DB::table(GuideSchema::table('virtual_users'))
                ->whereRaw('convert(lower(email) using utf8mb4) collate utf8mb4_bin in ('.$this->marks($bare).')', $bare);
            foreach (($lock ? $mailboxes->sharedLock() : $mailboxes)->pluck('email') as $email) {
                $email = $this->lower($email);
                if (isset($out[$email])) {
                    $out[$email]['via'] = 'mailbox';
                }
                $bare = array_values(array_diff($bare, [$email]));
            }
        }
        if ($bare === []) {
            return $out;
        }

        $catchAlls = [];
        foreach ($bare as $address) {
            $catchAlls['@'.substr($address, (int) strpos($address, '@') + 1)][] = $address;
        }
        $destinations = [];
        $fromRows = [];
        foreach (array_keys($catchAlls) as $key) {
            if (isset($override[$key])) {
                $destinations[$key] = $override[$key];
            } else {
                $fromRows[] = $key;
            }
        }
        if ($fromRows !== []) {
            foreach ($this->rows($lock)->whereRaw(self::SRC.' in ('.$this->marks($fromRows).')', $fromRows)->orderBy('id')->get(['source', 'destination']) as $row) {
                $destinations[$this->lower($row->source ?? null)][] = $this->lower($row->destination ?? null);
            }
        }
        foreach ($destinations as $key => $list) {
            foreach ($catchAlls[$key] ?? [] as $address) {
                $local = substr($address, 0, (int) strpos($address, '@'));
                $to = [];
                foreach ($list as $destination) {
                    $to[] = str_starts_with($destination, '@') ? $local.$destination : $destination;
                }
                $out[$address] = ['via' => 'catch_all', 'to' => $to];
            }
        }

        return $out;
    }

    /**
     * The targets of a new catch-all $catchAll (`@domain`) that Postfix could not deliver, keyed as given:
     *
     * - `unknown`: the target, or an address its mail passes through, is on the catch-all's own domain
     *   but is neither a mailbox nor a source with rows, so the catch-all takes the mail again (a typo,
     *   or an address that doesn't exist);
     * - `loops`: its mail comes back to an address it already passed through that doesn't expand to
     *   itself (a chain of forwarding, two catch-alls sending to each other, a mailbox that forwards
     *   without keeping a copy into the catch-all). Postfix then expands round the loop until
     *   virtual_alias_recursion_limit and defers the mail.
     *
     * The catch-all counts as forwarding to $targets already. Each target's walk looks at most
     * MAX_EXPANSION addresses, as Postfix gives up after that many too.
     *
     * @param  array<int, string>  $targets  lowercased, keyed by position in the request
     * @return array<int, array{0: 'unknown'|'loops', 1: string}> the problem and the address to name:
     *                                                            the unknown one, or the target that loops
     */
    public function catchAllProblems(string $catchAll, array $targets, bool $lock): array
    {
        $catchAll = strtolower($catchAll);
        $suffix = $catchAll; // `@domain`
        $override = [$catchAll => array_values($targets)];
        $memo = [];
        $step = function (string $address) use (&$memo, $lock, $override): array {
            return $memo[$address] ??= $this->step([$address], $lock, $override)[$address];
        };

        $problems = [];
        foreach ($targets as $i => $target) {
            // Depth first, with each address's path, so a diamond (two routes to one alias) is no loop.
            $stack = [[$target, [$target => true]]];
            $visits = 0;
            while ($stack !== [] && $visits++ < self::MAX_EXPANSION) {
                [$address, $path] = array_pop($stack);
                $next = $step($address);
                if (str_ends_with($address, $suffix) && strpos($address, '@') > 0 && ($next['via'] === 'catch_all' || $next['via'] === 'none')) {
                    $problems[$i] = ['unknown', $address];
                    break;
                }
                foreach ($next['to'] as $destination) {
                    if ($destination === $address) {
                        continue; // the kept copy: delivered here, and marked "been here"
                    }
                    if (isset($path[$destination])) {
                        if (! in_array($destination, $step($destination)['to'], true)) {
                            $problems[$i] = ['loops', $target];
                            break 2;
                        }

                        continue;
                    }
                    $stack[] = [$destination, $path + [$destination => true]];
                }
            }
        }

        return $problems;
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

    private function lower(mixed $value): string
    {
        return strtolower(is_string($value) ? $value : '');
    }

    private function rows(bool $lock): Builder
    {
        $query = DB::table(GuideSchema::table('virtual_aliases'));

        return $lock ? $query->sharedLock() : $query;
    }
}
