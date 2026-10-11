<?php

use App\MailServer\Dns\Cidr;

it('tells whether a network covers an address', function (string $cidr, string $ip, bool $covered) {
    expect(Cidr::contains($cidr, $ip))->toBe($covered);
})->with([
    'ip4 /24 inside' => ['192.0.2.0/24', '192.0.2.10', true],
    'ip4 /24 outside' => ['192.0.2.0/24', '192.0.3.10', false],
    'ip4 bare is /32' => ['192.0.2.10', '192.0.2.10', true],
    'ip4 bare other' => ['192.0.2.10', '192.0.2.11', false],
    'ip4 /0' => ['0.0.0.0/0', '203.0.113.5', true],
    'ip4 /31 edge' => ['192.0.2.10/31', '192.0.2.11', true],
    'ip4 /31 just out' => ['192.0.2.10/31', '192.0.2.12', false],
    'ip4 host bits set in the network' => ['192.0.2.77/24', '192.0.2.10', true],
    'ip4 /27 boundary' => ['192.0.2.32/27', '192.0.2.63', true],
    'ip4 /27 next block' => ['192.0.2.32/27', '192.0.2.64', false],
    'ip6 /32 inside' => ['2001:db8::/32', '2001:db8::10', true],
    'ip6 /32 outside' => ['2001:db8::/32', '2001:db9::10', false],
    'ip6 bare is /128' => ['2001:db8::10', '2001:0db8:0:0:0:0:0:10', true],
    'ip6 /64 inside' => ['2001:db8:1:2::/64', '2001:db8:1:2:ffff::1', true],
    'ip6 /63 odd bits' => ['2001:db8:1:2::/63', '2001:db8:1:3::1', true],
    'ip6 /63 odd bits out' => ['2001:db8:1:2::/63', '2001:db8:1:4::1', false],
    'family mismatch' => ['192.0.2.0/24', '2001:db8::10', false],
    'family mismatch 6 to 4' => ['::/0', '192.0.2.10', false],
    'ip4 prefix too long' => ['192.0.2.0/33', '192.0.2.10', false],
    'ip6 prefix too long' => ['2001:db8::/129', '2001:db8::10', false],
    'negative prefix' => ['192.0.2.0/-1', '192.0.2.10', false],
    'junk prefix' => ['192.0.2.0/abc', '192.0.2.10', false],
    'empty prefix' => ['192.0.2.0/', '192.0.2.10', false],
    'junk network' => ['example.org/24', '192.0.2.10', false],
    'junk address' => ['192.0.2.0/24', 'nonsense', false],
]);
