<?php

use App\MailServer\Dkim;
use App\Models\DomainDkim;
use App\Models\HelperTask;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

/*
 * DKIM end to end on the trixie host, against the real rspamd that Postfix hands every message to (the
 * guide's milter on 11332): the console queues each step, the real helper runs it as _rspamd with the
 * grants `emailsquire:grants` prints, and rspamd signs with the key the selector map names. The helper
 * never reloads rspamd: the test waits for rspamd to notice the rewritten map by itself, as it must on a
 * real server. The host carries the install step of docs/backend-plan.md §9 (the map in
 * /etc/rspamd/emailsquire, selector_map pointed there; docker/trixie-host/setup.sh). The test puts the
 * map and the key directory back as it found them in `finally`, even when an assertion fails.
 */

const DKT_KEYS = '/var/lib/rspamd/dkim';
const DKT_MAP = '/etc/rspamd/emailsquire/dkim_selectors.map';

/**
 * rspamd watches a file map's stat (mtime and size) and rereads it within moments of the rename; the
 * ceiling allows for its slower poll (map_watch_interval × map_file_watch_multiplier, 30 s by default).
 */
const DKT_MAP_WAIT_SECONDS = 150;

beforeEach(function () {
    GuideFixture::load('trixie');

    $this->actingAs(User::factory()->create(['is_server_admin' => true]));

    // Apply exactly what `emailsquire:grants` prints for _rspamd: this proves those grants are enough for the helper.
    expect(Artisan::call('emailsquire:grants', ['--database' => 'migrator']))->toBe(0);
    $grants = array_filter(
        explode("\n", Artisan::output()),
        fn (string $line) => preg_match('/^(CREATE USER|GRANT) /', $line) === 1 && str_contains($line, "'_rspamd'@'localhost'"),
    );
    expect($grants)->toHaveCount(3);
    foreach ($grants as $line) {
        DB::connection('migrator')->statement($line);
    }
});

/** @return array{map: string|null, entries: list<string>} */
function dktSnapshot(): array
{
    return [
        'map' => is_file(DKT_MAP) ? (string) file_get_contents(DKT_MAP) : null,
        'entries' => array_values(array_diff((array) scandir(DKT_KEYS), ['.', '..'])),
    ];
}

/** @param  array{map: string|null, entries: list<string>}  $snapshot */
function dktRestore(array $snapshot): void
{
    if ($snapshot['map'] === null) {
        if (is_file(DKT_MAP)) {
            unlink(DKT_MAP);
        }
    } else {
        file_put_contents(DKT_MAP, $snapshot['map']);
    }
    foreach (array_diff((array) scandir(DKT_KEYS), ['.', '..'], $snapshot['entries']) as $entry) {
        if (is_file(DKT_KEYS."/{$entry}")) {
            unlink(DKT_KEYS."/{$entry}");
        }
    }
}

/** Runs the DKIM helper as _rspamd against the test app schema, as emailsquire-dkim.service runs it. */
function dktRunHelper(): void
{
    $process = new Process(['runuser', '-u', '_rspamd', '--', 'php', '/app/helpers/dkim.php', 'run', '--app-schema=emailsquire_test']);
    $process->run();
    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
}

/** Queues a DKIM step through the API (with step-up), lets the helper run it, and returns the key list. */
function dktStep($test, string $path, array $json = []): array
{
    confirmStepUp();
    $test->postJson("/api/v1/domains/example.org/dkim/{$path}", $json)->assertAccepted();
    dktRunHelper();

    $state = $test->getJson('/api/v1/domains/example.org/dkim')->assertOk()->json();
    expect($state['pending_task'])->toBeNull((string) json_encode($state['pending_task']));

    return $state;
}

/**
 * Sends a message from john@example.org through Postfix (port 25, from 127.0.0.1: one of rspamd's local
 * networks, which it signs for) to a fresh mailbox and returns the delivered file.
 */
function dktSend(string $subject): string
{
    $local = apiLocal('dkim');
    insertMailbox("{$local}@example.org", 'x');
    (new Process(['swaks', '--server', 'localhost', '--from', 'john@example.org', '--to', "{$local}@example.org",
        '--header', "Subject: {$subject}", '--body', "Signed by rspamd.\nSecond line.  With  spaces.\n", '--silent']))->mustRun();
    $messages = waitForMaildirMessages($local, 30);
    expect($messages)->toHaveCount(1);

    return $messages[0];
}

/**
 * Sends messages until rspamd signs one with $selector (it reads the rewritten selector map on its own
 * schedule), and returns that message.
 */
function dktSendUntilSigned(string $selector): string
{
    $deadline = microtime(true) + DKT_MAP_WAIT_SECONDS;
    do {
        $message = dktSend("probe for {$selector}");
        if ((dktSignature($message)['s'] ?? null) === $selector) {
            return $message;
        }
        sleep(3);
    } while (microtime(true) < $deadline);

    throw new RuntimeException("rspamd did not sign with {$selector} within ".DKT_MAP_WAIT_SECONDS.' s; last: '.substr($message, 0, 2000));
}

/**
 * The headers of a message (LF or CRLF) as [name, raw value with folding] in order, and its body.
 *
 * @return array{0: list<array{0: string, 1: string}>, 1: string}
 */
function dktParse(string $message): array
{
    $message = str_replace("\r\n", "\n", $message);
    [$head, $body] = array_pad(explode("\n\n", $message, 2), 2, '');
    $headers = [];
    foreach (explode("\n", $head) as $line) {
        if ($line !== '' && ($line[0] === ' ' || $line[0] === "\t") && $headers !== []) {
            $headers[array_key_last($headers)][1] .= "\r\n".$line;
        } elseif (str_contains($line, ':')) {
            [$name, $value] = explode(':', $line, 2);
            $headers[] = [$name, $value];
        }
    }

    return [$headers, $body];
}

/** The tags of the message's DKIM-Signature header, or [] without one. @return array<string, string> */
function dktSignature(string $message): array
{
    foreach (dktParse($message)[0] as [$name, $value]) {
        if (strcasecmp($name, 'DKIM-Signature') === 0) {
            $tags = [];
            foreach (explode(';', preg_replace('/\s+/', '', $value) ?? '') as $pair) {
                if (str_contains($pair, '=')) {
                    [$tag, $tagValue] = explode('=', $pair, 2);
                    $tags[$tag] = $tagValue;
                }
            }

            return $tags;
        }
    }

    return [];
}

/** RFC 6376 §3.4.2 relaxed header canonicalization. */
function dktRelaxedHeader(string $name, string $value): string
{
    $value = preg_replace('/[ \t]+/', ' ', str_replace("\r\n", '', $value)) ?? '';

    return strtolower(trim($name)).':'.trim($value)."\r\n";
}

/**
 * Verifies the message's DKIM signature (rsa-sha256, relaxed/relaxed, RFC 6376) with the public key of
 * `$txt`, the record the console tells the admin to publish: the body hash and the RSA signature.
 */
function dktVerify(string $message, string $txt): bool
{
    [$headers, $body] = dktParse($message);
    $tags = dktSignature($message);
    expect($tags['a'] ?? null)->toBe('rsa-sha256')
        ->and($tags['c'] ?? null)->toBe('relaxed/relaxed');

    // Relaxed body: trailing white space dropped, runs of it made one space, empty lines at the end dropped.
    $lines = array_map(fn (string $l) => rtrim(preg_replace('/[ \t]+/', ' ', $l) ?? '', ' '), explode("\n", $body));
    while ($lines !== [] && end($lines) === '') {
        array_pop($lines);
    }
    $canonicalBody = $lines === [] ? '' : implode("\r\n", $lines)."\r\n";
    if (! hash_equals($tags['bh'] ?? '', base64_encode(hash('sha256', $canonicalBody, true)))) {
        return false;
    }

    // The signed headers, each instance taken from the bottom up; a name listed more often than it
    // appears (rspamd oversigns) adds nothing.
    $remaining = $headers;
    $signed = '';
    foreach (explode(':', $tags['h'] ?? '') as $wanted) {
        foreach (array_reverse(array_keys($remaining)) as $i) {
            if (strcasecmp($remaining[$i][0], $wanted) === 0) {
                $signed .= dktRelaxedHeader($remaining[$i][0], $remaining[$i][1]);
                unset($remaining[$i]);
                break;
            }
        }
    }
    foreach ($headers as [$name, $value]) {
        if (strcasecmp($name, 'DKIM-Signature') === 0) {
            $unsigned = preg_replace('/(^|;)(\s*b\s*=)[^;]*/', '$1$2', $value) ?? '';
            $signed .= rtrim(dktRelaxedHeader($name, $unsigned), "\r\n");
            break;
        }
    }

    preg_match('/(?:^|;)\s*p=([A-Za-z0-9+\/=]+)/', $txt, $p);
    $public = openssl_pkey_get_public("-----BEGIN PUBLIC KEY-----\n".chunk_split($p[1] ?? '', 64, "\n")."-----END PUBLIC KEY-----\n");
    expect($public)->not->toBeFalse();

    return openssl_verify($signed, (string) base64_decode($tags['b'] ?? '', true), $public, OPENSSL_ALGO_SHA256) === 1;
}

/** @return array<string, string> doctor status per check id, for the DKIM install checks */
function dktDoctor(): array
{
    Artisan::call('emailsquire:doctor', ['--json' => true]);
    $results = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    return array_column(array_filter($results, fn (array $r) => str_starts_with($r['id'], 'fs.dkim')), 'status', 'id');
}

it('signs mail with a key created and activated from the console, rotates to a new key, and retires the old one', function () {
    $snapshot = dktSnapshot();
    try {
        // The host has the documented install step: doctor's DKIM checks pass.
        expect(dktDoctor())->toBe(['fs.dkim_dir' => 'ok', 'fs.dkim_selector_map' => 'ok']);

        // Generate: a key the console shows, not used yet.
        $state = dktStep($this, 'generate');
        expect($state['keys'])->toHaveCount(1);
        [$first] = $state['keys'];
        expect($first['state'])->toBe('pending')
            ->and($first['selector'])->toMatch('/^es\d{8}$/')
            ->and($first['txt'])->toStartWith('v=DKIM1; k=rsa; p=');
        $selector = $first['selector'];
        $keyFile = DKT_KEYS."/example.org.{$selector}.key";
        $stat = stat($keyFile);
        expect(posix_getpwuid($stat['uid'])['name'])->toBe('_rspamd')
            ->and($stat['mode'] & 0777)->toBe(0600)
            ->and(file_exists(DKT_MAP) ? file_get_contents(DKT_MAP) : '')->not->toContain('example.org');
        // Only the public half ever reaches the database.
        expect(HelperTask::query()->pluck('result')->toJson())->not->toContain('PRIVATE');

        // Activate with force (no public DNS here): the helper points the map at the key.
        $state = dktStep($this, "{$selector}/activate", ['force' => true]);
        expect($state['active'])->toBe($selector)
            ->and(file_get_contents(DKT_MAP))->toContain("example.org {$selector}");

        // rspamd notices the map by itself and signs; the signature checks out with the published record.
        $message = dktSendUntilSigned($selector);
        $signature = dktSignature($message);
        expect($signature['d'])->toBe('example.org')
            ->and($signature['s'])->toBe($selector)
            ->and(dktVerify($message, $first['txt']))->toBeTrue();

        // Rotate: a second key the same day gets the next letter, and rspamd switches to it.
        $state = dktStep($this, 'generate');
        $second = collect($state['keys'])->firstWhere('state', 'pending');
        expect($second['selector'])->toBe("{$selector}b");
        $state = dktStep($this, "{$selector}b/activate", ['force' => true]);
        expect($state['active'])->toBe("{$selector}b")
            ->and(collect($state['keys'])->firstWhere('selector', $selector)['state'])->toBe('pending');
        $message = dktSendUntilSigned("{$selector}b");
        expect(dktVerify($message, $second['txt']))->toBeTrue()
            ->and(dktVerify($message, $first['txt']))->toBeFalse();

        // Retire the old key: not yet (rspamd may still sign with it until it reloads the map), but once
        // the 15-minute hold has passed. Its file is deleted; the active key stays.
        confirmStepUp();
        $this->postJson("/api/v1/domains/example.org/dkim/{$selector}/retire")->assertConflict()
            ->assertJsonPath('code', 'dkim_recently_activated');
        // The hold counts from when the helper finished the activation; move that back instead of waiting.
        HelperTask::query()->where('type', 'dkim.activate')->update(['updated_at' => now()->subSeconds(Dkim::RETIRE_HOLD_SECONDS + 60)]);
        $state = dktStep($this, "{$selector}/retire");
        expect(collect($state['keys'])->firstWhere('selector', $selector)['state'])->toBe('retired')
            ->and(file_exists($keyFile))->toBeFalse()
            ->and(file_exists(DKT_KEYS."/example.org.{$selector}b.key"))->toBeTrue()
            ->and(DomainDkim::query()->where('selector', "{$selector}b")->value('state'))->toBe('active')
            ->and(file_get_contents(DKT_MAP))->toContain("example.org {$selector}b");
        expect(dktSignature(dktSend('after retiring'))['s'] ?? null)->toBe("{$selector}b");
    } finally {
        dktRestore($snapshot);
    }
});
