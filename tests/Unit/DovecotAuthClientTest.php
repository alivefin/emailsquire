<?php

use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\DovecotAuthFailure;

it('builds the exact AUTH PLAIN line', function () {
    expect(DovecotAuthClient::authLine(1, 'john@example.org', 'summersun'))
        ->toBe("AUTH\t1\tPLAIN\tservice=emailsquire\tnologin\tresp=AGpvaG5AZXhhbXBsZS5vcmcAc3VtbWVyc3Vu\n");
});

it('adds the remote IP as rip', function (string $ip) {
    expect(DovecotAuthClient::authLine(1, 'john@example.org', 'summersun', $ip))
        ->toBe("AUTH\t1\tPLAIN\tservice=emailsquire\tnologin\trip={$ip}\tresp=AGpvaG5AZXhhbXBsZS5vcmcAc3VtbWVyc3Vu\n");
})->with(['IPv4' => '192.0.2.10', 'IPv6' => '2001:db8::1']);

it('rejects a remote IP that is not an IP address, without connecting', function (string $ip) {
    $client = new DovecotAuthClient('/nonexistent/emailsquire-auth', 1);

    expect(fn () => $client->verify('john@example.org', 'summersun', $ip))
        ->toThrow(InvalidArgumentException::class);
})->with(['tab' => "192.0.2.10\tfoo", 'newline' => "1.2.3.4\nAUTH"]);

it('refuses NUL bytes without connecting', function () {
    $client = new DovecotAuthClient('/nonexistent/emailsquire-auth', 1);

    expect($client->verify("john@example.org\0x", 'p'))->toBeFalse()
        ->and($client->verify('john@example.org', "p\0q"))->toBeFalse();
});

it('throws Unreachable for a missing socket', function () {
    $client = new DovecotAuthClient('/nonexistent/emailsquire-auth', 1);

    expect(fn () => $client->verify('john@example.org', 'summersun'))
        ->toThrow(function (DovecotAuthException $e) {
            expect($e->reason)->toBe(DovecotAuthFailure::Unreachable);
        });
});

it('leaves no stale state after a failed standalone handshake', function () {
    $client = new DovecotAuthClient('/nonexistent/emailsquire-auth', 1);

    foreach ([1, 2] as $_) {
        expect(fn () => $client->handshake())
            ->toThrow(function (DovecotAuthException $e) {
                expect($e->reason)->toBe(DovecotAuthFailure::Unreachable);
            });
    }
});
