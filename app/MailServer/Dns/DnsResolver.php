<?php

namespace App\MailServer\Dns;

/**
 * The DNS seam of the health checks (spec decision 7). The server uses SystemDnsResolver
 * (dns_get_record), the e2e environment FakeDnsResolver (a fixture), and the Pest suite its own stub.
 *
 * Names are compared lowercased and without a trailing dot; implementations answer either spelling.
 */
interface DnsResolver
{
    public const TYPES = ['MX', 'TXT', 'A', 'AAAA', 'PTR', 'CNAME'];

    /**
     * The records of $type at $name. Each has a `value`: the target name (MX, PTR, CNAME), the address
     * (A, AAAA) or the text with its strings joined (TXT). MX records add `pri`; TXT records add
     * `entries`, the strings as published (a record longer than 255 bytes is split into several).
     *
     * @param  'MX'|'TXT'|'A'|'AAAA'|'PTR'|'CNAME'|string  $type
     * @return list<array{value: string, pri?: int, entries?: list<string>}> none for NXDOMAIN or an empty answer
     *
     * @throws DnsLookupFailed when the lookup itself fails: SERVFAIL, a timeout, a name that isn't one
     */
    public function records(string $name, string $type): array;
}
