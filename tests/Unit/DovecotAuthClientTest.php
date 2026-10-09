<?php

use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\DovecotAuthFailure;

it('builds the exact AUTH PLAIN line', function () {
    expect(DovecotAuthClient::authLine(1, 'john@example.org', 'summersun'))
        ->toBe("AUTH\t1\tPLAIN\tservice=emailsquire\tnologin\tresp=AGpvaG5AZXhhbXBsZS5vcmcAc3VtbWVyc3Vu\n");
});

it('refuses NUL bytes without connecting', function () {
    $client = new DovecotAuthClient('/nonexistent/emailsquire-auth', 1);

    expect($client->verify("john@example.org\0x", 'p'))->toBeFalse()
        ->and($client->verify('john@example.org', "p\0q"))->toBeFalse();
});

it('throws Unreachable for a missing socket', function () {
    $client = new DovecotAuthClient('/nonexistent/emailsquire-auth', 1);

    expect(fn () => $client->verify('john@example.org', 'summersun'))
        ->toThrow(fn (DovecotAuthException $e) => $e->reason === DovecotAuthFailure::Unreachable);
});
