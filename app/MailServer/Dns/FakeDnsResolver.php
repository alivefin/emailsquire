<?php

namespace App\MailServer\Dns;

use InvalidArgumentException;
use LogicException;

/**
 * The e2e environment's stand-in for DNS (Playwright has no zone to query): fixed records from a JSON
 * fixture, tests/e2e/fixtures/dns.json. AppServiceProvider binds it only when APP_ENV is e2e, and it
 * refuses to answer anywhere else; the doctor's runtime.test_environment check fails an e2e or testing
 * environment on a real mail root.
 *
 * The fixture maps each name to its record types, each a list in shorthand (record()), or the string
 * "SERVFAIL" for a lookup that fails. A name or type it doesn't list answers like NXDOMAIN:
 *
 *     {"example.org": {"MX": ["10 mail.example.org."], "TXT": ["v=spf1 mx -all"]}}
 */
final class FakeDnsResolver implements DnsResolver
{
    /** @var array<string, mixed>|null the fixture, read on the first lookup */
    private ?array $zone = null;

    public function __construct(private readonly string $path) {}

    public function records(string $name, string $type): array
    {
        if (! app()->environment('e2e')) {
            throw new LogicException('FakeDnsResolver answers only when APP_ENV is e2e.');
        }

        $zone = $this->zone ??= $this->load();
        $byType = $zone[strtolower(rtrim($name, '.'))] ?? [];
        $records = is_array($byType) ? ($byType[$type] ?? []) : [];
        if ($records === 'SERVFAIL') {
            throw new DnsLookupFailed("SERVFAIL for {$name} {$type} (fixture).");
        }

        return is_array($records) ? array_values(array_map(fn (mixed $r) => self::record($type, $r), $records)) : [];
    }

    /**
     * One record from its shorthand: `"10 mail.example.org."` for MX, a string or a list of strings
     * (the chunks as published) for TXT, an address for A and AAAA, a name for PTR and CNAME.
     *
     * @return array{value: string, pri?: int, entries?: list<string>}
     */
    public static function record(string $type, mixed $shorthand): array
    {
        if ($type === 'TXT') {
            $entries = is_array($shorthand) ? array_values(array_filter($shorthand, 'is_string')) : [self::text($shorthand)];

            return ['value' => implode('', $entries), 'entries' => $entries];
        }
        $text = self::text($shorthand);
        if ($type === 'MX') {
            if (preg_match('/^(\d+)\s+(\S+)$/', $text, $m) !== 1) {
                throw new InvalidArgumentException("An MX shorthand is \"<priority> <host>\", not \"{$text}\".");
            }

            return ['value' => $m[2], 'pri' => (int) $m[1]];
        }

        return ['value' => $text];
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : throw new InvalidArgumentException('A DNS fixture record must be a string.');
    }

    /** @return array<string, mixed> */
    private function load(): array
    {
        $json = @file_get_contents($this->path);
        $zone = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($zone)) {
            throw new LogicException("The DNS fixture {$this->path} is missing or not a JSON object.");
        }

        $lower = [];
        foreach ($zone as $name => $records) {
            $lower[strtolower(rtrim((string) $name, '.'))] = $records;
        }

        return $lower;
    }
}
