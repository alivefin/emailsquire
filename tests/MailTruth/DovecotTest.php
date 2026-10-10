<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use App\MailServer\Dovecot\DoveadmFailure;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');

    // Requests without a remote IP (no rip=, as in these tests) share one auth-penalty bucket (the
    // empty IP). Each failure with a password the bucket hasn't seen recently delays the next
    // request, successful or not, by up to 15 s, and a success resets it to zero. Reset it here so
    // the request under test never waits on an earlier test's penalty; the 30 s timeout covers the
    // maximum delay this reset itself may have to wait out.
    (new DovecotAuthClient((string) config('emailsquire.dovecot_auth.socket'), 30))->verify('john@example.org', 'summersun');
});

it('accepts the right password for every scheme the guide produces', function (string $user) {
    expect(app(DovecotAuthClient::class)->verify($user, 'summersun'))->toBeTrue();
})->with([
    'BLF-CRYPT (guide)' => 'john@example.org',
    'CRYPT $2y$ (Roundcube)' => 'mary@example.org',
]);

it('accepts a legacy SHA512-CRYPT hash', function () {
    insertMailbox('legacy@example.org', '{SHA512-CRYPT}'.crypt('summersun', '$6$'.bin2hex(random_bytes(8)).'$'));

    expect(app(DovecotAuthClient::class)->verify('legacy@example.org', 'summersun'))->toBeTrue();
});

it('rejects a wrong password and an unknown user', function (string $user, string $password) {
    expect(app(DovecotAuthClient::class)->verify($user, $password))->toBeFalse();
})->with([
    'wrong password' => ['john@example.org', 'wrong'],
    'unknown user' => ['nobody@example.org', 'summersun'],
]);

it('rejects every password for a mailbox disabled with a hash of random bytes', function () {
    insertMailbox('disabled@example.org', '{BLF-CRYPT}'.password_hash(bin2hex(random_bytes(32)), PASSWORD_BCRYPT));

    expect(app(DovecotAuthClient::class)->verify('disabled@example.org', 'summersun'))->toBeFalse();
});

it('keeps auth penalties per client IP', function () {
    $client = app(DovecotAuthClient::class);
    $ips = ['192.0.2.1', '192.0.2.2', '192.0.2.3', '192.0.2.4'];

    // These IPs keep their penalty across runs; reset each bucket like beforeEach does for the empty IP.
    $reset = new DovecotAuthClient((string) config('emailsquire.dovecot_auth.socket'), 30);
    foreach ([...$ips, '192.0.2.5'] as $ip) {
        $reset->verify('john@example.org', 'summersun', $ip);
    }

    // Distinct wrong passwords: in one shared bucket, these would push the next request's delay to 15 s.
    foreach ($ips as $ip) {
        expect($client->verify('john@example.org', 'wrong-'.bin2hex(random_bytes(6)), $ip))->toBeFalse();
    }

    $start = microtime(true);
    $ok = $client->verify('john@example.org', 'summersun', '192.0.2.5');
    $elapsed = microtime(true) - $start;

    expect($ok)->toBeTrue()
        ->and($elapsed)->toBeLessThan(5.0);
});

it('exposes the auth socket to the emailsquire user only', function () {
    $socket = '/run/dovecot/emailsquire-auth';

    expect(filetype($socket))->toBe('socket')
        ->and(fileperms($socket) & 0777)->toBe(0600)
        ->and(posix_getpwuid((int) fileowner($socket))['name'] ?? null)->toBe('emailsquire');
});

it('maps EACCES on the real socket to PermissionDenied', function () {
    $process = new Process(['su', '-s', '/bin/sh', 'nobody', '-c', 'php /app/tests/MailTruth/fixtures/handshake-as-user.php']);
    $process->run();

    expect(trim($process->getOutput()))->toBe('PermissionDenied', $process->getErrorOutput());
});

it('reports the storage quota limit in KiB, following the quota column', function () {
    $storageLimit = function (): string {
        $rows = app(DoveadmClient::class)->call('quotaGet', ['user' => 'john@example.org']);

        return collect($rows)->firstWhere('type', 'STORAGE')['limit'];
    };

    expect($storageLimit())->toBe('5242880');

    DB::table(GuideSchema::table('virtual_users'))->where('email', 'john@example.org')->update(['quota' => 1024000]);

    expect($storageLimit())->toBe('1000');
});

it('kicks a user and reports the count', function () {
    $reply = app(DoveadmClient::class)->call('kick', ['mask' => ['john@example.org']]);

    expect($reply)->toHaveCount(1)
        ->and($reply[0])->toHaveKey('count');
});

it('returns a single userdb field', function () {
    $reply = app(DoveadmClient::class)->call('user', ['userMask' => ['john@example.org'], 'field' => 'home']);

    expect($reply)->toBe(['john@example.org' => ['home' => '/var/vmail/example.org/john']]);
});

it('fails with exit code 67 for an unknown user', function () {
    expect(fn () => app(DoveadmClient::class)->call('user', ['userMask' => ['nobody@example.org']]))
        ->toThrow(function (DoveadmException $e) {
            expect($e->reason)->toBe(DoveadmFailure::CommandError)
                ->and($e->exitCode)->toBe(67);
        });
});
