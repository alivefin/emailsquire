<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\MailboxSessions;
use App\MailServer\QuotaFormat;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

beforeEach(fn () => app()->instance(DoveadmClient::class, doveadmClient()));

it('kicks in chunks of 50', function () {
    fakeDoveadm('doveadmResponse', []);
    $addresses = array_map(fn ($i) => "u{$i}@x.fi", range(1, 120));

    app(MailboxSessions::class)->kick(...$addresses);

    Http::assertSentCount(3);
    Http::assertSent(fn (Request $r) => $r->data()[0][0] === 'kick' && count((array) $r->data()[0][1]['mask']) <= 50);
});

it('never throws and logs the count but no address', function () {
    Http::fake(fn () => throw new ConnectionException('down'));
    Log::spy();

    app(MailboxSessions::class)->kick('secret@x.fi');

    Log::shouldHaveReceived('warning')->once()->withArgs(
        fn ($message, $context = []) => $message === 'doveadm kick failed' && ! str_contains(json_encode($context), 'secret@x.fi') && ! str_contains($message, 'secret@x.fi'),
    );
});

it('does nothing for no addresses', function () {
    app(MailboxSessions::class)->kick();
    Http::assertNothingSent();
});

it('turns a stored zero quota into the default', function () {
    expect(QuotaFormat::effectiveBytes(0))->toBe(5368709120)
        ->and(QuotaFormat::effectiveBytes(1048576))->toBe(1048576);
});
