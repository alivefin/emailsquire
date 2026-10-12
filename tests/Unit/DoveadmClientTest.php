<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Dovecot\DoveadmFailure;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

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
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::Unauthorized);
        });
});

it('maps command-level unAuthorized to a command authorization failure', function () {
    fakeDoveadm('error', ['type' => 'unAuthorized']);

    expect(fn () => doveadmClient()->call('fetch', ['user' => 'nobody@example.org', 'field' => ['guid'], 'query' => ['mailbox', bin2hex(random_bytes(16))]]))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason->name)->toBe('CommandUnauthorized');
        });
});

it('maps a connection failure to Unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    expect(fn () => doveadmClient()->call('who'))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::Unreachable);
        });
});

it('maps an error reply to CommandError with the exit code', function () {
    Http::fake(fn (Request $request) => Http::response([['error', ['type' => 'exitCode', 'exitCode' => 67], $request->data()[0][2]]]));

    expect(fn () => doveadmClient()->call('user', ['userMask' => ['nobody@example.org']]))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::CommandError)
                ->and($e->exitCode)->toBe(67);
        });
});

it('maps a mismatched tag to Malformed', function () {
    Http::fake(fn () => Http::response([['doveadmResponse', [], 'wrongtag']]));

    expect(fn () => doveadmClient()->call('who'))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::Malformed);
        });
});

it('maps a non-JSON body to Malformed', function () {
    Http::fake(fn () => Http::response('<html>nope</html>', 200));

    expect(fn () => doveadmClient()->call('who'))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::Malformed);
        });
});

it('resolves from the container without an api key', function () {
    config(['emailsquire.doveadm.api_key' => null]);

    expect(app(DoveadmClient::class))->toBeInstanceOf(DoveadmClient::class);
});

it('maps batched replies by tag, whatever their order', function () {
    Http::fake(fn (Request $request) => Http::response(array_reverse(array_map(
        fn (array $c) => ['doveadmResponse', [['command' => $c[0]]], $c[2]],
        $request->data(),
    ))));

    $results = doveadmClient()->callMany([['a', []], ['b', ['x' => 1]], ['c', []]]);

    expect($results)->toBe([[['command' => 'a']], [['command' => 'b']], [['command' => 'c']]]);
});

it('puts a per-command error in its slot and still returns the others', function () {
    fakeDoveadmBatch(fn (string $command) => $command === 'bad'
        ? ['error', ['type' => 'exitCode', 'exitCode' => 67]]
        : ['doveadmResponse', [['ok' => $command]]]);

    $results = doveadmClient()->callMany([['one', []], ['bad', []], ['two', []]]);

    expect($results[0])->toBe([['ok' => 'one']])
        ->and($results[1])->toBeInstanceOf(DoveadmException::class)
        ->and($results[1]->exitCode)->toBe(67)
        ->and($results[1]->reason)->toBe(DoveadmFailure::CommandError)
        ->and($results[2])->toBe([['ok' => 'two']]);
});

it('throws Malformed when a batched reply is missing a tag', function () {
    Http::fake(fn (Request $request) => Http::response([['doveadmResponse', [], $request->data()[0][2]]]));

    expect(fn () => doveadmClient()->callMany([['a', []], ['b', []]]))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::Malformed);
        });
});

it('throws Malformed on a duplicate tag in a batched reply', function () {
    Http::fake(fn (Request $request) => Http::response([
        ['doveadmResponse', [], $request->data()[0][2]],
        ['doveadmResponse', [], $request->data()[0][2]],
    ]));

    expect(fn () => doveadmClient()->callMany([['a', []], ['b', []]]))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::Malformed);
        });
});

it('sends no request for an empty batch', function () {
    Http::fake();

    expect(doveadmClient()->callMany([]))->toBe([]);

    Http::assertNothingSent();
});

it('keeps invalidRequest distinct from command authorization and command exit errors', function () {
    fakeDoveadm('error', ['type' => 'invalidRequest']);

    expect(fn () => doveadmClient()->call('fetch', ['user' => 'doctor-probe@invalid', 'field' => ['guid'], 'query' => ['mailbox', bin2hex(random_bytes(16))]]))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::Malformed)
                ->and($e->getMessage())->toContain('invalidRequest');
        });
});
