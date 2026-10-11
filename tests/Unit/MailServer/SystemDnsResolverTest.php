<?php

use App\MailServer\Dns\DnsLookupFailed;
use App\MailServer\Dns\SystemDnsResolver;

// The real resolver, with dns_get_record() replaced by a closure: the suite never queries real DNS.

/** @param  Closure(string, int): (array<mixed>|false)  $query */
function sdrResolver(Closure $query): SystemDnsResolver
{
    return new SystemDnsResolver(query: $query);
}

it('answers an empty list for NXDOMAIN or an empty answer', function () {
    expect(sdrResolver(fn () => [])->records('example.org', 'MX'))->toBe([]);
});

it('throws DnsLookupFailed when dns_get_record fails (SERVFAIL, a timeout), and keeps its warning out of the error handler', function () {
    $resolver = sdrResolver(function () {
        trigger_error('dns_get_record(): A temporary server error occurred.', E_USER_WARNING);

        return false;
    });

    expect(fn () => $resolver->records('example.org', 'TXT'))->toThrow(DnsLookupFailed::class);
});

it('asks for the record type requested as an absolute name, and keeps only records of that type', function () {
    $asked = [];
    $resolver = sdrResolver(function (string $name, int $type) use (&$asked) {
        $asked[] = [$name, $type];

        return [
            ['host' => 'example.org', 'class' => 'IN', 'ttl' => 300, 'type' => 'MX', 'pri' => 10, 'target' => 'mail.example.org'],
            ['host' => 'example.org', 'class' => 'IN', 'ttl' => 300, 'type' => 'CNAME', 'target' => 'other.example.org'],
            ['host' => 'example.org', 'type' => 'MX', 'pri' => 'junk'], // no target: dropped
        ];
    });

    expect($resolver->records('Example.ORG.', 'MX'))->toBe([['value' => 'mail.example.org', 'pri' => 10]])
        ->and($asked)->toBe([['example.org.', DNS_MX]]);
});

it('normalises each record type', function (string $type, array $raw, array $expected) {
    expect(sdrResolver(fn () => [$raw])->records('example.org', $type))->toBe([$expected]);
})->with([
    'TXT keeps its chunks' => ['TXT', ['type' => 'TXT', 'txt' => 'v=spf1 -all', 'entries' => ['v=spf1 ', '-all']], ['value' => 'v=spf1 -all', 'entries' => ['v=spf1 ', '-all']]],
    'TXT without entries' => ['TXT', ['type' => 'TXT', 'txt' => 'v=spf1 -all'], ['value' => 'v=spf1 -all', 'entries' => ['v=spf1 -all']]],
    'A' => ['A', ['type' => 'A', 'ip' => '192.0.2.10'], ['value' => '192.0.2.10']],
    'AAAA' => ['AAAA', ['type' => 'AAAA', 'ipv6' => '2001:db8::10'], ['value' => '2001:db8::10']],
    'PTR' => ['PTR', ['type' => 'PTR', 'target' => 'mail.example.org'], ['value' => 'mail.example.org']],
    'CNAME' => ['CNAME', ['type' => 'CNAME', 'target' => 'mail.example.org'], ['value' => 'mail.example.org']],
]);

it('refuses a record type it does not know', function () {
    expect(fn () => sdrResolver(fn () => [])->records('example.org', 'ANY'))->toThrow(InvalidArgumentException::class);
});

it('treats a name that is not a DNS name as a failed lookup, without asking', function (string $name) {
    $asked = false;
    $resolver = sdrResolver(function () use (&$asked) {
        $asked = true;

        return [];
    });

    expect(fn () => $resolver->records($name, 'TXT'))->toThrow(DnsLookupFailed::class)
        ->and($asked)->toBeFalse();
})->with(['', 'exa mple.org', "example.org\0", 'a..b', str_repeat('a', 64).'.org', str_repeat('abcdefghi.', 26).'org', 'ex/ample.org']);

it('never sets RES_OPTIONS, which glibc would not read again anyway', function () {
    $seen = 'unread';
    sdrResolver(function () use (&$seen) {
        $seen = getenv('RES_OPTIONS');

        return [];
    })->records('example.org', 'A');

    expect($seen)->toBe(getenv('RES_OPTIONS'));
});
