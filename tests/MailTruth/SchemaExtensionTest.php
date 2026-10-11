<?php

use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

/*
 * Backend plan section 10 against the real thing: the optional `enabled` and `sendonly` columns, with the
 * documented Postfix and Dovecot changes applied to the trixie host. The test rewrites three config files
 * and restores them (and drops the columns) in `finally`, even when an assertion fails.
 *
 * The documented changes (keep these in step with section 10):
 *  - mariadb-virtual-mailbox-maps.cf and mariadb-email2email.cf: `... AND enabled = 1 AND sendonly = 0`
 *  - Dovecot userdb query: `... AND (enabled = 1 AND sendonly = 0 OR '%{protocol}' <> 'lmtp')`
 */

const SXT_MAILBOX_MAP = '/etc/postfix/mariadb-virtual-mailbox-maps.cf';
const SXT_EMAIL2EMAIL = '/etc/postfix/mariadb-email2email.cf';
const SXT_DOVECOT_SQL = '/etc/dovecot/conf.d/99-ispmail-sql.conf';
const SXT_POSTFIX_LOG = '/var/log/postfix.log';

beforeEach(function () {
    GuideFixture::load('trixie');
    // The fixture's catch-all (@example.org -> john) would take direct mail to a refused mailbox.
    DB::table(GuideSchema::table('virtual_aliases'))->where('source', '@example.org')->delete();
});

/** @param  list<string>  $command */
function sxtRun(array $command): void
{
    (new Process($command))->mustRun();
}

/** Applies the section 10 server changes; returns the original files to hand back to sxtRestore(). */
function sxtApply(): array
{
    $original = [];
    foreach ([SXT_MAILBOX_MAP, SXT_EMAIL2EMAIL, SXT_DOVECOT_SQL] as $path) {
        $original[$path] = (string) file_get_contents($path);
    }

    $gate = ' AND enabled = 1 AND sendonly = 0';
    file_put_contents(SXT_MAILBOX_MAP, str_replace("WHERE email='%s'", "WHERE email='%s'{$gate}", $original[SXT_MAILBOX_MAP]));
    file_put_contents(SXT_EMAIL2EMAIL, str_replace("WHERE email='%s'", "WHERE email='%s'{$gate}", $original[SXT_EMAIL2EMAIL]));
    file_put_contents(SXT_DOVECOT_SQL, str_replace(
        "FROM virtual_users WHERE email='%{user}'\n  iterate_query",
        "FROM virtual_users WHERE email='%{user}' \\\n    AND (enabled = 1 AND sendonly = 0 OR '%{protocol}' <> 'lmtp')\n  iterate_query",
        $original[SXT_DOVECOT_SQL],
    ));
    expect(file_get_contents(SXT_DOVECOT_SQL))->toContain("'%{protocol}' <> 'lmtp'");
    sxtReload();

    return $original;
}

/** @param  array<string, string>  $original */
function sxtRestore(array $original): void
{
    foreach ($original as $path => $content) {
        file_put_contents($path, $content);
    }
    sxtReload();
}

function sxtReload(): void
{
    sxtRun(['postfix', 'reload']);
    sxtRun(['doveadm', 'reload']);
    usleep(500_000);
}

/** Creates a mailbox with the password `summersun`, in the extended table. */
function sxtMailbox(string $local): string
{
    $address = "{$local}@example.org";
    insertMailbox($address, '{BLF-CRYPT}'.password_hash('summersun', PASSWORD_BCRYPT, ['cost' => 5]));

    return $address;
}

function sxtAlias(string $source, string $destination): void
{
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 10, 'source' => $source, 'destination' => $destination]);
}

/** @return array{ok: bool, output: string} Postfix's answer to RCPT TO, read through the SMTP dialogue. */
function sxtRcpt(string $to): array
{
    $process = new Process(['swaks', '--server', 'localhost', '--to', $to, '--from', 'sender@example.net', '--quit-after', 'RCPT']);
    $process->run();

    return ['ok' => $process->isSuccessful(), 'output' => $process->getOutput().$process->getErrorOutput()];
}

/** Sends a whole message and returns Postfix's log lines about $recipient's delivery, once it is no longer queued. */
function sxtSendAndLog(string $to, string $recipient): string
{
    $offset = (int) filesize(SXT_POSTFIX_LOG);
    sendMail($to);

    $deadline = microtime(true) + 20;
    do {
        usleep(300_000);
        $fresh = (string) file_get_contents(SXT_POSTFIX_LOG, false, null, $offset);
        $lines = array_values(array_filter(explode("\n", $fresh), fn (string $l) => str_contains($l, "to=<{$recipient}>") && str_contains($l, 'status=')));
        $final = array_filter($lines, fn (string $l) => ! str_contains($l, 'status=deferred'));
    } while ($final === [] && microtime(true) < $deadline);

    return implode("\n", $lines);
}

/** Whether Dovecot's IMAP LOGIN on port 143 accepts $address with `summersun`. */
function sxtImapLogin(string $address): bool
{
    $socket = stream_socket_client('tcp://127.0.0.1:143', $errno, $error, 5);
    expect($socket)->not->toBeFalse();
    stream_set_timeout($socket, 30);
    fgets($socket);
    fwrite($socket, "a1 LOGIN \"{$address}\" \"summersun\"\r\n");
    $reply = '';
    while (($line = fgets($socket)) !== false) {
        $reply .= $line;
        if (str_starts_with($line, 'a1 ')) {
            break;
        }
    }
    fwrite($socket, "a2 LOGOUT\r\n");
    fclose($socket);

    return str_contains($reply, 'a1 OK');
}

it('stops delivery, direct and through aliases, while the owner can still sign in, for each switch', function (string $column, int $off) {
    $local = apiLocal('sx');
    $address = sxtMailbox($local);
    $via = apiLocal('al').'@example.org';
    sxtAlias($via, $address);
    sxtAlias($address, $address);  // keep-a-copy row: the address is an alias source, too

    $original = [];
    try {
        GuideFixture::addMailboxExtension();
        $original = sxtApply();

        // Switch on (the default): delivered, directly and through the alias.
        expect(sxtRcpt($address)['ok'])->toBeTrue();
        sendMail($address);
        sendMail($via);
        expect(deliveredCount($local, 2))->toBe(2);

        // Switch off.
        DB::table(GuideSchema::table('virtual_users'))->where('email', $address)->update([$column => $off]);

        // A keep-a-copy row makes the address an alias source: RCPT is accepted, but Dovecot's LMTP refuses the delivery.
        expect(sxtRcpt($address)['ok'])->toBeTrue();
        $copyLog = sxtSendAndLog($address, $address);
        expect($copyLog)->toContain('status=bounced')->and($copyLog)->toContain("User doesn't exist");
        expect(deliveredCount($local))->toBe(2);

        // Direct mail: with a keep-copy row the address is an alias source, so RCPT is still accepted; remove it.
        DB::table(GuideSchema::table('virtual_aliases'))->where('source', $address)->delete();
        $direct = sxtRcpt($address);
        expect($direct['ok'])->toBeFalse()->and($direct['output'])->toContain('550');

        // Through the alias: accepted at RCPT (the alias row exists), refused by Dovecot's LMTP, bounced by Postfix.
        $before = deliveredCount($local);
        expect($before)->toBe(2);
        $log = sxtSendAndLog($via, $address);
        expect($log)->toContain('status=bounced')->and($log)->toContain('550');
        expect(deliveredCount($local))->toBe($before);

        // The owner still signs in over IMAP.
        expect(sxtImapLogin($address))->toBeTrue();

        // Back on: delivered again, both ways.
        DB::table(GuideSchema::table('virtual_users'))->where('email', $address)->update([$column => 1 - $off]);
        expect(sxtRcpt($address)['ok'])->toBeTrue();
        sendMail($address);
        sendMail($via);
        expect(deliveredCount($local, $before + 2))->toBe($before + 2);
    } finally {
        if ($original !== []) {
            sxtRestore($original);
        }
        GuideFixture::dropMailboxExtension();
    }
})->with([
    'receiving off (enabled = 0)' => ['enabled', 0],
    'send-only (sendonly = 1)' => ['sendonly', 1],
]);
