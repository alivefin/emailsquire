<?php

use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');
});

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

it('delivers mail for an alias to its destination mailbox', function () {
    $before = deliveredCount('john');

    sendMail('jack@example.org');

    expect(deliveredCount('john', $before + 1))->toBe($before + 1);
});

it('delivers to every destination of a keep-a-copy alias', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'mary@example.org'],
    ]);
    $john = deliveredCount('john');
    $mary = deliveredCount('mary');

    sendMail('john@example.org');

    expect(deliveredCount('john', $john + 1))->toBe($john + 1)
        ->and(deliveredCount('mary', $mary + 1))->toBe($mary + 1);
});
