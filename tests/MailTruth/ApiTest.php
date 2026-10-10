<?php

use App\MailServer\Dovecot\DovecotAuthClient;
use App\Models\HelperTask;
use App\Models\User;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');

    // Same auth-penalty reset as DovecotTest: a failure in the shared empty-IP bucket delays the next request.
    (new DovecotAuthClient((string) config('emailsquire.dovecot_auth.socket'), 30))->verify('john@example.org', 'summersun');

    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
});

/** Verifies through the real auth socket from a fixed documentation IP, away from the shared empty-IP bucket. */
function dovecotAccepts(string $user, string $password): bool
{
    return (new DovecotAuthClient((string) config('emailsquire.dovecot_auth.socket'), 30))
        ->verify($user, $password, '192.0.2.77');
}

it('authenticates and receives mail for a mailbox created through the API', function () {
    $local = apiLocal('api');
    createApiMailbox($this, $local);

    expect(dovecotAccepts("{$local}@example.org", API_TEST_PASSWORD))->toBeTrue()
        ->and(postmapQuery("{$local}@example.org", 'virtual-mailbox-maps'))->not->toBe('');

    sendMail("{$local}@example.org");

    expect(deliveredCount($local, 1))->toBe(1);
});

it('fails auth but still receives mail for a disabled mailbox, and enabling restores auth', function () {
    $local = apiLocal('toggle');
    createApiMailbox($this, $local);

    $this->postJson("/api/v1/domains/example.org/mailboxes/{$local}/disable")->assertNoContent();

    expect(dovecotAccepts("{$local}@example.org", API_TEST_PASSWORD))->toBeFalse();
    sendMail("{$local}@example.org");
    expect(deliveredCount($local, 1))->toBe(1);

    $this->postJson("/api/v1/domains/example.org/mailboxes/{$local}/enable")->assertNoContent();

    expect(dovecotAccepts("{$local}@example.org", API_TEST_PASSWORD))->toBeTrue();
});

it('delivers to both with keep_copy and only to the target without it', function () {
    $source = apiLocal('src');
    $target = apiLocal('dst');
    createApiMailbox($this, $source);
    createApiMailbox($this, $target);

    $this->putJson("/api/v1/domains/example.org/forwarding/{$source}", [
        'targets' => ["{$target}@example.org"], 'keep_copy' => true, 'redirect_mailbox' => false,
    ])->assertSuccessful();

    sendMail("{$source}@example.org");
    expect(deliveredCount($source, 1))->toBe(1)
        ->and(deliveredCount($target, 1))->toBe(1);

    $this->putJson("/api/v1/domains/example.org/forwarding/{$source}", [
        'targets' => ["{$target}@example.org"], 'keep_copy' => false, 'redirect_mailbox' => true,
    ])->assertSuccessful();

    sendMail("{$source}@example.org");
    expect(deliveredCount($target, 2))->toBe(2)
        ->and(deliveredCount($source))->toBe(1);
});

it('removes a deleted mailbox from postmap and keeps its address 409 until the archive task leaves pending', function () {
    $local = apiLocal('gone');
    createApiMailbox($this, $local);
    expect(postmapQuery("{$local}@example.org", 'virtual-mailbox-maps'))->not->toBe('');

    $this->deleteJson("/api/v1/domains/example.org/mailboxes/{$local}")->assertSuccessful();

    expect(postmapQuery("{$local}@example.org", 'virtual-mailbox-maps'))->toBe('');

    $recreate = fn () => $this->postJson('/api/v1/domains/example.org/mailboxes', [
        'local' => $local, 'password' => API_TEST_PASSWORD, 'quota_bytes' => 104857600,
    ]);
    $recreate()->assertStatus(409);

    // The maildir helper is plan 1b; mark its task done by hand.
    expect(HelperTask::query()->where('type', 'maildir.archive')->where('address', "{$local}@example.org")->update(['status' => 'done']))->toBe(1);

    $recreate()->assertCreated();
});

it('kicks an open IMAP session when the password is reset', function () {
    $local = apiLocal('kick');
    $address = "{$local}@example.org";
    createApiMailbox($this, $local);

    $socket = stream_socket_client('tcp://127.0.0.1:143', $errno, $error, 5);
    expect($socket)->not->toBeFalse();
    stream_set_timeout($socket, 5);
    fgets($socket); // greeting
    fwrite($socket, 'a1 LOGIN '.$address.' '.API_TEST_PASSWORD."\r\n");
    $reply = '';
    while (($line = fgets($socket)) !== false) {
        $reply .= $line;
        if (str_starts_with($line, 'a1 ')) {
            break;
        }
    }
    expect($reply)->toContain('a1 OK');

    $who = function () use ($address): bool {
        $process = new Process(['doveadm', 'who']);
        $process->run();

        return str_contains($process->getOutput(), $address);
    };
    $waitFor = function (bool $listed) use ($who): bool {
        $deadline = microtime(true) + 5;
        while ($who() !== $listed && microtime(true) < $deadline) {
            usleep(200_000);
        }

        return $who();
    };

    expect($waitFor(true))->toBeTrue();

    $this->postJson("/api/v1/domains/example.org/mailboxes/{$local}/password", ['generate' => true])
        ->assertOk()->assertJsonStructure(['password']);

    $listedAfter = $waitFor(false);
    fclose($socket);

    expect($listedAfter)->toBeFalse();
});
