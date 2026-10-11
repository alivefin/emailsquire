<?php

namespace Tests\Support;

use App\MailServer\Dns\DnsLookupFailed;
use App\MailServer\Dns\DnsResolver;
use App\MailServer\Dns\FakeDnsResolver;

/**
 * The Pest suite's DNS: an in-memory zone, so no test ever queries a real resolver. Every Feature test
 * gets an empty one (tests/Pest.php), where every name answers like NXDOMAIN; a test sets the records it
 * needs with set(), in the shorthand of tests/e2e/fixtures/dns.json (FakeDnsResolver::record()), and
 * makes a lookup fail like SERVFAIL with fail().
 */
final class StubDnsResolver implements DnsResolver
{
    /** @var array<string, list<array{value: string, pri?: int, entries?: list<string>}>|false> "name TYPE" => records, false for SERVFAIL */
    private array $zone = [];

    /** @var list<string> every lookup, as "name TYPE" */
    public array $queries = [];

    /** @param  list<mixed>  $records  shorthand records: "10 mail.example.org" (MX), a string or list of chunks (TXT), an address or a name */
    public function set(string $name, string $type, array $records): self
    {
        $this->zone[self::key($name, $type)] = array_values(array_map(fn (mixed $r) => FakeDnsResolver::record($type, $r), $records));

        return $this;
    }

    public function fail(string $name, string $type): self
    {
        $this->zone[self::key($name, $type)] = false;

        return $this;
    }

    public function records(string $name, string $type): array
    {
        $key = self::key($name, $type);
        $this->queries[] = $key;
        $records = $this->zone[$key] ?? [];
        if ($records === false) {
            throw new DnsLookupFailed("SERVFAIL for {$key}");
        }

        return $records;
    }

    private static function key(string $name, string $type): string
    {
        return strtolower(rtrim($name, '.')).' '.strtoupper($type);
    }
}
