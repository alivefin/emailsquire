<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\GuideSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

// Global test helpers shared across test files; autoloaded via composer.json autoload-dev "files".

const API_TEST_PASSWORD = 'Summersun-2026!';

/**
 * Maildirs under /var/vmail outlive a test run (the fixture reload only resets the database), so every
 * test uses a fresh local part and starts counting from an empty Maildir.
 */
function apiLocal(string $prefix): string
{
    return $prefix.bin2hex(random_bytes(3));
}

/** Creates <local>@example.org through the API with a 100 MiB quota. */
function createApiMailbox($test, string $local): void
{
    $test->postJson('/api/v1/domains/example.org/mailboxes', [
        'local' => $local, 'password' => API_TEST_PASSWORD, 'quota_bytes' => 104857600,
    ])->assertCreated();
}

/**
 * Runs the maildir helper as vmail against the test app schema, as emailsquire-maildir.service runs it
 * against the real one, and returns the finished process.
 */
function runMaildirHelper(string $mode): Process
{
    $process = new Process(['runuser', '-u', 'vmail', '--', 'php', '/app/helpers/maildir.php', $mode, '--app-schema=emailsquire_test']);
    $process->run();

    return $process;
}

function insertMailbox(string $email, string $password): void
{
    DB::table(GuideSchema::table('virtual_users'))->insert([
        'domain_id' => 10,
        'email' => $email,
        'password' => $password,
        'quota' => 0,
    ]);
}

/** Runs `postmap -q` against one of the guide's MariaDB map files, as Postfix would. */
function postmapQuery(string $key, string $map): string
{
    $process = new Process(['postmap', '-q', $key, "mysql:/etc/postfix/mariadb-{$map}.cf"]);
    $process->run();

    return trim($process->getOutput());
}

/**
 * Counts the messages in a mailbox's Maildir/new. Delivery is asynchronous, so it polls
 * for up to 10 s until at least $atLeast messages are there, then returns the count.
 */
function deliveredCount(string $local, int $atLeast = 0): int
{
    $dir = "/var/vmail/example.org/{$local}/Maildir/new";
    $deadline = microtime(true) + 10;

    do {
        $count = is_dir($dir) ? count(array_diff((array) scandir($dir), ['.', '..'])) : 0;
        if ($count >= $atLeast) {
            return $count;
        }
        usleep(200_000);
    } while (microtime(true) < $deadline);

    return $count;
}

function sendMail(string $to): void
{
    (new Process(['swaks', '--server', 'localhost', '--to', $to, '--silent']))->mustRun();
}

function guideCount(string $table): int
{
    $schema = config('emailsquire.guide_schema');

    return (int) DB::selectOne("select count(*) as n from `{$schema}`.`{$table}`")->n;
}

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

/**
 * Fake a batched doveadm reply. $reply gets each command and its parameters and returns
 * [type, payload] for that command; the fake echoes each command's tag.
 *
 * @param  Closure(string, array<string, mixed>): array{0: string, 1: mixed}  $reply
 */
function fakeDoveadmBatch(Closure $reply): void
{
    Http::fake(fn (Request $request) => Http::response(array_map(function (array $command) use ($reply) {
        [$type, $payload] = $reply($command[0], (array) $command[1]);

        return [$type, $payload, $command[2]];
    }, $request->data())));
}
