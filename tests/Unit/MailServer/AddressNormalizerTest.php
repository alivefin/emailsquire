<?php

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;

dataset('accepted addresses', [
    'trimmed and lowercased' => [' Anna@Example.ORG ', 'anna@example.org', 'example.org'],
    'idn domain' => ['anna@exämple.org', 'anna@xn--exmple-cua.org', 'exämple.org'],
    'uppercase idn domain' => ['anna@EXÄMPLE.org', 'anna@xn--exmple-cua.org', 'exämple.org'],
    'dots hyphens underscores' => ['a.b-c_d@x.fi', 'a.b-c_d@x.fi', 'x.fi'],
]);

it('normalises an address', function (string $input, string $address, string $unicode) {
    $result = (new AddressNormalizer)->address($input);

    expect($result->address())->toBe($address)
        ->and($result->domain->unicode)->toBe($unicode);
})->with('accepted addresses');

dataset('refused addresses', [
    'empty' => ['', 'empty'],
    'blank' => ['   ', 'empty'],
    'double dot' => ['a..b@x.fi', 'local_chars'],
    'leading dot' => ['.a@x.fi', 'local_chars'],
    'trailing dot' => ['a.@x.fi', 'local_chars'],
    'plus' => ['a+b@x.fi', 'local_chars'],
    'space' => ['a b@x.fi', 'local_chars'],
    'percent' => ['a%b@x.fi', 'local_chars'],
    'slash' => ['a/b@x.fi', 'local_chars'],
    'fullwidth' => ["\u{FF41}nna@x.fi", 'not_ascii'],
    'cyrillic' => ["\u{0430}nna@x.fi", 'not_ascii'],
    'single label domain' => ['anna@x', 'domain_format'],
    'leading hyphen' => ['anna@-x.fi', 'domain_format'],
    'empty label' => ['anna@x..fi', 'domain_format'],
    'trailing dot domain' => ['anna@x.fi.', 'domain_format'],
    'no at sign' => ['anna', 'domain_format'],
    'long label' => ['anna@'.str_repeat('a', 64).'.fi', 'domain_format'],
    'long domain' => ['anna@'.str_repeat('a', 30).'.'.str_repeat('b', 17).'.fi', 'too_long'],
    'long address' => [str_repeat('a', 60).'@'.str_repeat('b', 37).'.fi', 'too_long'],
]);

it('refuses a bad address with its reason', function (string $input, string $reason) {
    try {
        (new AddressNormalizer)->address($input);
    } catch (InvalidAddress $e) {
        expect($e->reason)->toBe($reason);

        return;
    }

    $this->fail('Expected InvalidAddress');
})->with('refused addresses');

it('uses the specified long inputs', function () {
    expect(strlen('anna@'.str_repeat('a', 30).'.'.str_repeat('b', 17).'.fi'))->toBe(5 + 51)
        ->and(strlen(str_repeat('a', 60).'@'.str_repeat('b', 37).'.fi'))->toBe(101);
});

it('never accepts a NUL byte', function () {
    expect(fn () => (new AddressNormalizer)->address("anna@x.fi\0"))->toThrow(InvalidAddress::class);
    expect(fn () => (new AddressNormalizer)->address("an\0na@x.fi"))->toThrow(InvalidAddress::class);
});

it('normalises domains', function () {
    $n = new AddressNormalizer;

    expect($n->domain('Example.ORG')->ascii)->toBe('example.org')
        ->and($n->domain('exämple.org')->ascii)->toBe('xn--exmple-cua.org')
        ->and($n->domain('exämple.org')->unicode)->toBe('exämple.org');
});

it('normalises a local part and builds an address in a domain', function () {
    $n = new AddressNormalizer;

    expect($n->local(' Anna.B '))->toBe('anna.b')
        ->and($n->addressIn('Anna', $n->domain('x.fi'))->address())->toBe('anna@x.fi');
    expect(fn () => $n->local('a+b'))->toThrow(InvalidAddress::class);
    expect(fn () => $n->addressIn(str_repeat('a', 97), $n->domain('x.fi')))->toThrow(InvalidAddress::class);
});

it('carries the specified messages', function () {
    $n = new AddressNormalizer;
    $msg = function (string $in) use ($n) {
        try {
            $n->address($in);
        } catch (InvalidAddress $e) {
            return $e->getMessage();
        }
    };

    expect($msg(''))->toBe('Enter an address.')
        ->and($msg('a+b@x.fi'))->toBe('Only letters, numbers, dots, hyphens and underscores — no spaces.')
        ->and($msg('anna@x'))->toBe('That doesn’t look like a domain name.')
        ->and($msg(str_repeat('a', 101).'@x.fi'))->toBe('Addresses can be at most 100 characters and domains 50.')
        ->and($msg("\u{0430}@x.fi"))->toBe('Use letters a–z in the part before @.');
});
