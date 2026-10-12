<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Dovecot\DoveadmFailure;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

it('the real doveadm key refuses fetch while allowing quotaGet', function () {
    GuideFixture::load('trixie');
    $client = app(DoveadmClient::class);

    expect(fn () => $client->call('fetch', ['user' => 'john@example.org', 'field' => ['guid'], 'query' => ['mailbox', bin2hex(random_bytes(16))]]))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::CommandUnauthorized);
        });

    expect($client->call('quotaGet', ['user' => 'john@example.org']))->not->toBeEmpty();
});
