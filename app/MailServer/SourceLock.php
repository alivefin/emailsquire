<?php

namespace App\MailServer;

use App\MailServer\Exceptions\Conflict;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Serialises changes to one source address (a mailbox or a forwarding source) across requests.
 *
 * MariaDB limits lock names to 64 characters and an address can be 100, so the name is
 * `emailsquire:src:` plus the first 48 hex characters of the address's sha256 (64 in all).
 * The address is lowercased first: legacy rows may be mixed case, and every writer of one address must share one lock.
 * (sha1 would fit too, but the security arch preset forbids it.)
 */
final class SourceLock
{
    public static function name(string $address): string
    {
        return 'emailsquire:src:'.substr(hash('sha256', strtolower($address)), 0, 48);
    }

    /**
     * Runs $fn holding the address lock. Callers open their DB::transaction inside $fn, so the lock
     * is released only after the transaction has committed and the next holder sees its rows.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public static function run(string $address, Closure $fn): mixed
    {
        $name = self::name($address);
        $row = DB::selectOne('select get_lock(?, ?) as acquired', [$name, config()->integer('emailsquire.source_lock_timeout')]);
        $acquired = is_object($row) && is_numeric($row->acquired ?? null) && (int) $row->acquired === 1;

        if (! $acquired) {
            throw new Conflict('busy', 'Someone else is changing this address. Try again.');
        }

        try {
            return $fn();
        } finally {
            DB::selectOne('select release_lock(?) as released', [$name]);
        }
    }
}
