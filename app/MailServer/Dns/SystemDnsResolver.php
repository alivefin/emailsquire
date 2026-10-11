<?php

namespace App\MailServer\Dns;

use Closure;
use InvalidArgumentException;

/**
 * The server's resolver: dns_get_record(), which asks the resolvers in /etc/resolv.conf.
 *
 * PHP has no per-call timeout. On glibc, each dns_get_record() call reads RES_OPTIONS afresh
 * (res_ninit), so the lookup sets `timeout:<n> attempts:<n>` for the length of the call and puts the
 * old value back: a dead resolver costs at most timeout × attempts per name server and lookup. The
 * queued job's own `$timeout` (CheckDomainDns) is the backstop for everything else.
 *
 * dns_get_record() answers [] for NXDOMAIN and NODATA, and false with a warning for SERVFAIL, a
 * timeout or no resolver; the warning is caught here and becomes DnsLookupFailed.
 */
final class SystemDnsResolver implements DnsResolver
{
    private const FLAGS = ['MX' => DNS_MX, 'TXT' => DNS_TXT, 'A' => DNS_A, 'AAAA' => DNS_AAAA, 'PTR' => DNS_PTR, 'CNAME' => DNS_CNAME];

    /** @var Closure(string, int): (array<mixed>|false) */
    private readonly Closure $query;

    /** @param  (Closure(string, int): (array<mixed>|false))|null  $query  dns_get_record by default; tests replace it */
    public function __construct(
        private readonly int $timeout = 3,
        private readonly int $attempts = 2,
        ?Closure $query = null,
    ) {
        $this->query = $query ?? static fn (string $name, int $type): array|false => dns_get_record($name, $type);
    }

    public function records(string $name, string $type): array
    {
        $flag = self::FLAGS[$type] ?? throw new InvalidArgumentException("Unsupported DNS record type {$type}.");
        $name = strtolower(rtrim($name, '.'));
        if (! self::isDnsName($name)) {
            throw new DnsLookupFailed('Not a DNS name.');
        }

        $raw = $this->bounded(fn () => ($this->query)($name, $flag), $warning);
        if ($raw === false) {
            throw new DnsLookupFailed("{$type} lookup for {$name} failed".($warning !== null ? ": {$warning}" : '.'));
        }

        $records = [];
        foreach ($raw as $record) {
            if (is_array($record) && ($record['type'] ?? null) === $type && ($normal = self::normalise($type, $record)) !== null) {
                $records[] = $normal;
            }
        }

        return $records;
    }

    /**
     * Runs $lookup with RES_OPTIONS set and PHP warnings caught into $warning.
     *
     * @param  Closure(): (array<mixed>|false)  $lookup
     * @return array<mixed>|false
     */
    private function bounded(Closure $lookup, ?string &$warning): array|false
    {
        $warning = null;
        $previous = getenv('RES_OPTIONS');
        putenv("RES_OPTIONS=timeout:{$this->timeout} attempts:{$this->attempts}");
        set_error_handler(function (int $level, string $message) use (&$warning): bool {
            $warning = $message;

            return true;
        });
        try {
            return $lookup();
        } finally {
            restore_error_handler();
            putenv($previous === false ? 'RES_OPTIONS' : "RES_OPTIONS={$previous}");
        }
    }

    /**
     * @param  array<mixed>  $r  one record as dns_get_record() returns it
     * @return array{value: string, pri?: int, entries?: list<string>}|null
     */
    private static function normalise(string $type, array $r): ?array
    {
        $field = match ($type) {
            'A' => 'ip',
            'AAAA' => 'ipv6',
            'TXT' => 'txt',
            default => 'target',
        };
        $value = $r[$field] ?? null;
        if (! is_string($value)) {
            return null;
        }

        return match ($type) {
            'MX' => is_int($r['pri'] ?? null) ? ['value' => $value, 'pri' => $r['pri']] : null,
            'TXT' => ['value' => $value, 'entries' => self::entries($r['entries'] ?? null, $value)],
            default => ['value' => $value],
        };
    }

    /** @return list<string> */
    private static function entries(mixed $entries, string $txt): array
    {
        if (! is_array($entries)) {
            return [$txt];
        }

        return array_values(array_filter($entries, 'is_string'));
    }

    /** At most 253 characters of labels of 1 to 63 letters, digits, hyphens and underscores (_dmarc, _domainkey). */
    private static function isDnsName(string $name): bool
    {
        return strlen($name) <= 253 && preg_match('/^[a-z0-9_-]{1,63}(\.[a-z0-9_-]{1,63})*$/D', $name) === 1;
    }
}
