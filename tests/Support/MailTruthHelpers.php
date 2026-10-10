<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\GuideSchema;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

// Global test helpers shared across test files; autoloaded via composer.json autoload-dev "files".

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
