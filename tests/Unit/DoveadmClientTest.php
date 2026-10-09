<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Dovecot\DoveadmFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function doveadmClient(): DoveadmClient
{
    return new DoveadmClient(
        config()->string('emailsquire.doveadm.url'),
        config()->string('emailsquire.doveadm.api_key'),
        config()->integer('emailsquire.doveadm.timeout'),
    );
}

/** Fake a 200 reply whose tag echoes the request's tag. */
function fakeDoveadm(string $type, mixed $payload): void
{
    Http::fake(fn (Request $request) => Http::response([[$type, $payload, $request->data()[0][2]]]));
}

it('returns the payload for quotaGet', function () {
    $rows = [
        ['root' => 'User quota', 'type' => 'STORAGE', 'value' => '1', 'limit' => '5242880', 'percent' => '0'],
        ['root' => 'User quota', 'type' => 'MESSAGE', 'value' => '1', 'limit' => '-', 'percent' => '0'],
    ];
    fakeDoveadm('doveadmResponse', $rows);

    expect(doveadmClient()->call('quotaGet', ['user' => 'john@example.org']))->toBe($rows);
});

it('sends the X-Dovecot-API authorization header', function () {
    fakeDoveadm('doveadmResponse', [['count' => '0']]);

    doveadmClient()->call('kick', ['mask' => ['john@example.org']]);

    Http::assertSent(fn (Request $request) => $request->header('Authorization') === ['X-Dovecot-API dGVzdC1hcGkta2V5']
        && str_contains($request->body(), '"kick",{"mask":["john@example.org"]}'));
});

it('encodes empty parameters as a JSON object', function () {
    fakeDoveadm('doveadmResponse', []);

    doveadmClient()->call('who');

    Http::assertSent(fn (Request $request) => str_contains($request->body(), '"who",{}'));
});

it('maps HTTP 401 to Unauthorized', function () {
    Http::fake(fn () => Http::response('', 401));

    expect(fn () => doveadmClient()->call('who'))
        ->toThrow(fn (DoveadmException $e) => $e->reason === DoveadmFailure::Unauthorized);
});

it('maps a connection failure to Unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(fn () => doveadmClient()->call('who'))
        ->toThrow(fn (DoveadmException $e) => $e->reason === DoveadmFailure::Unreachable);
});

it('maps an error reply to CommandError with the exit code', function () {
    Http::fake(fn (Request $request) => Http::response([['error', ['type' => 'exitCode', 'exitCode' => 67], $request->data()[0][2]]]));

    expect(fn () => doveadmClient()->call('user', ['userMask' => ['nobody@example.org']]))
        ->toThrow(fn (DoveadmException $e) => $e->reason === DoveadmFailure::CommandError && $e->exitCode === 67);
});

it('maps a mismatched tag to Malformed', function () {
    Http::fake(fn () => Http::response([['doveadmResponse', [], 'wrongtag']]));

    expect(fn () => doveadmClient()->call('who'))
        ->toThrow(fn (DoveadmException $e) => $e->reason === DoveadmFailure::Malformed);
});

it('maps a non-JSON body to Malformed', function () {
    Http::fake(fn () => Http::response('<html>nope</html>', 200));

    expect(fn () => doveadmClient()->call('who'))
        ->toThrow(fn (DoveadmException $e) => $e->reason === DoveadmFailure::Malformed);
});

it('resolves from the container without an api key', function () {
    config(['emailsquire.doveadm.api_key' => null]);

    expect(app(DoveadmClient::class))->toBeInstanceOf(DoveadmClient::class);
});
