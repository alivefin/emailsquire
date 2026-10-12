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
 * portal's loop check) and the console's forwarding checks (catchAllProblems() for a catch-all,
 * forwardingProblems() for a named source).
 *
 * Every compare is lowercased and binary, as in ForwardingList, so legacy mixed-case rows count. With
 * $lock alias rows are read with a shared lock to see current committed forwarding. Mailbox
 * existence is always a plain read: a later step of the walk must never lock mailboxes after aliases.
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

        // Mailbox existence never takes a lock: a previous step may already hold alias locks.
        // Writers lock required mailbox rows before starting the walk; alias rows take precedence.
        $named = array_values(array_filter($addresses, fn (string $a) => strpos($a, '@') > 0));
        $mailboxAddresses = [];
        if ($named !== []) {
            $mailboxes = DB::table(GuideSchema::table('virtual_users'))
                ->whereRaw('convert(lower(email) using utf8mb4) collate utf8mb4_bin in ('.$this->marks($named).')', $named)
                ->orderBy('email');
            foreach ($mailboxes->pluck('email') as $email) {
                $mailboxAddresses[$this->lower($email)] = true;
            }
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
        foreach ($bare as $email) {
            if (isset($mailboxAddresses[$email])) {
                $out[$email] = ['via' => 'mailbox', 'to' => []];
            }
        }
        $bare = array_values(array_filter($bare, fn (string $a) => ! isset($mailboxAddresses[$a])));
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
     * - `loops`: its mail comes back to an address it already passed through, and no address on that
     *   circle expands to itself (a chain of forwarding, two catch-alls sending to each other, a mailbox
     *   that forwards without keeping a copy into the catch-all). Postfix then expands round the loop
     *   until virtual_alias_recursion_limit and defers the mail;
     * - `too_large`: the walk looked at MAX_EXPANSION addresses without finishing. Postfix gives up after
     *   that many too, so the target is refused rather than accepted unchecked.
     *
     * The catch-all counts as forwarding to $targets already.
     *
     * @param  array<int, string>  $targets  lowercased, keyed by position in the request
     * @return array<int, array{0: 'unknown'|'loops'|'too_large', 1: string}> the problem and the address
     *                                                                        to name: the unknown one,
     *                                                                        or the target
     */
    public function catchAllProblems(string $catchAll, array $targets, bool $lock): array
    {
        $catchAll = strtolower($catchAll);

        return $this->walk([$catchAll => array_values($targets)], $targets, $lock, $catchAll);
    }

    /**
     * The targets of a named source's new forwarding (not a catch-all) that Postfix could not deliver,
     * keyed as given. $source counts as forwarding to $targets already, and to itself when $selfCopy
     * (the kept copy, which marks it "been here").
     *
     * - `unknown`, only when the source's domain has a catch-all: a target on that domain that is
     *   neither a mailbox nor a source with rows. The catch-all would take its mail, so a typo there
     *   loses the mail into the catch-all instead of bouncing it. (Only the targets themselves: an
     *   address further on that the catch-all takes is ordinary catch-all delivery.)
     * - `loops`: the target's mail comes back round a circle where no address expands to itself,
     *   through the source (forward-only, back to it) or not (an existing loop the mail would join);
     * - `too_large`: as for catchAllProblems().
     *
     * @param  array<int, string>  $targets  lowercased, keyed by position in the request
     * @return array<int, array{0: 'unknown'|'loops'|'too_large', 1: string}> the problem and the target
     */
    public function forwardingProblems(string $source, array $targets, bool $selfCopy, bool $lock): array
    {
        $source = strtolower($source);
        $override = [$source => [...array_values($targets), ...($selfCopy ? [$source] : [])]];

        $problems = [];
        $catchAll = '@'.substr($source, (int) strrpos($source, '@') + 1);
        if ($this->rows($lock)->whereRaw(self::SRC.' = ?', [$catchAll])->exists()) {
            $own = array_filter($targets, fn (string $t) => str_ends_with($t, $catchAll));
            $steps = $own === [] ? [] : $this->step(array_values(array_unique($own)), $lock, $override);
            foreach ($own as $i => $target) {
                if ($steps[$target]['via'] === 'catch_all' || $steps[$target]['via'] === 'none') {
                    $problems[$i] = ['unknown', $target];
                }
            }
        }

        return $problems + $this->walk($override, array_diff_key($targets, $problems), $lock, null);
    }

    /**
     * Walks each target's expansion depth first, with each address's path, so a diamond (two routes to
     * one alias) is no loop. A path that comes back to an address on it is a loop unless an address on
     * that circle expands to itself: Postfix marks such an address "been here" the first time it expands
     * it, for the whole message, and stops there the next time round.
     *
     * @param  array<string, list<string>>  $override  as for step()
     * @param  array<int, string>  $targets
     * @param  ?string  $unknownOn  `@domain`: an address on it that the catch-all would take (or nothing
     *                              would) is `unknown`, wherever on the walk it is
     * @return array<int, array{0: 'unknown'|'loops'|'too_large', 1: string}>
     */
    private function walk(array $override, array $targets, bool $lock, ?string $unknownOn): array
    {
        $memo = [];
        $step = function (string $address) use (&$memo, $lock, $override): array {
            return $memo[$address] ??= $this->step([$address], $lock, $override)[$address];
        };
        $expandsToItself = fn (string $address): bool => in_array($address, $step($address)['to'], true);

        $problems = [];
        foreach ($targets as $i => $target) {
            // Depth first with a global "done" set: an address expanded once, whatever route reached it, is
            // neither expanded nor counted again (Postfix dedupes recipients), so the bound counts distinct
            // addresses. The per-path set still finds loops: any circle reachable from an address shows up
            // as a way back to an address on the path while it is first expanded.
            $stack = [[$target, [$target => true]]];
            $done = [];
            $visits = 0;
            while ($stack !== []) {
                [$address, $path] = array_pop($stack);
                if ($address === null) {
                    $done[array_key_last($path)] = true; // exit marker: $path ends at the finished address

                    continue;
                }
                if (isset($done[$address])) {
                    continue;
                }
                if ($visits++ >= self::MAX_EXPANSION) {
                    $problems[$i] = ['too_large', $target];
                    break;
                }
                $stack[] = [null, $path];
                $next = $step($address);
                if ($unknownOn !== null && str_ends_with($address, $unknownOn) && strpos($address, '@') > 0
                    && ($next['via'] === 'catch_all' || $next['via'] === 'none')) {
                    $problems[$i] = ['unknown', $address];
                    break;
                }
                foreach ($next['to'] as $destination) {
                    if ($destination === $address) {
                        continue; // the kept copy: delivered here, and marked "been here"
                    }
                    if (isset($path[$destination])) {
                        // The circle: the path from $destination round to $address (keys keep their order).
                        $circle = array_slice(array_keys($path), (int) array_search($destination, array_keys($path), true));
                        if (array_filter($circle, $expandsToItself) === []) {
                            $problems[$i] = ['loops', $target];
                            break 2;
                        }

                        continue;
                    }
                    if (! isset($done[$destination])) {
                        $stack[] = [$destination, $path + [$destination => true]];
                    }
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
