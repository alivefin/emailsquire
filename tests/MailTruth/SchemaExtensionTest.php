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
 *  - mariadb-virtual-mailbox-maps.cf: `... AND enabled = 1 AND sendonly = 0`
 *  - a gated copy, mariadb-email2email-receiving.cf, used instead of mariadb-email2email.cf in virtual_alias_maps
 *    only; smtpd_sender_login_maps keeps the original (a gated one would stop the mailbox sending as itself)
 *  - Dovecot userdb query: `... AND (enabled = 1 AND sendonly = 0 OR '%{protocol}' <> 'lmtp')`
 */

const SXT_MAILBOX_MAP = '/etc/postfix/mariadb-virtual-mailbox-maps.cf';
const SXT_EMAIL2EMAIL = '/etc/postfix/mariadb-email2email.cf';
const SXT_RECEIVING = '/etc/postfix/mariadb-email2email-receiving.cf';
const SXT_MAIN_CF = '/etc/postfix/main.cf';
const SXT_MASTER_CF = '/etc/postfix/master.cf';
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

/**
 * The content of every file section 10 touches (null for a file that does not exist yet), taken before
 * anything is written, so sxtRestore() can undo a half-applied setup.
 *
 * @return array<string, string|null>
 */
function sxtSnapshot(): array
{
    $snapshot = [];
    foreach ([SXT_MAILBOX_MAP, SXT_EMAIL2EMAIL, SXT_RECEIVING, SXT_MAIN_CF, SXT_MASTER_CF, SXT_DOVECOT_SQL] as $path) {
        $snapshot[$path] = is_file($path) ? (string) file_get_contents($path) : null;
    }

    return $snapshot;
}

/** Applies the section 10 server changes (and the guide's submission service, which this host lacks), then reloads. */
function sxtApply(): void
{
    $mailboxMap = (string) file_get_contents(SXT_MAILBOX_MAP);
    $email2email = (string) file_get_contents(SXT_EMAIL2EMAIL);
    $dovecot = (string) file_get_contents(SXT_DOVECOT_SQL);
    $gate = ' AND enabled = 1 AND sendonly = 0';

    file_put_contents(SXT_MAILBOX_MAP, str_replace("WHERE email='%s'", "WHERE email='%s'{$gate}", $mailboxMap));
    file_put_contents(SXT_RECEIVING, str_replace("WHERE email='%s'", "WHERE email='%s'{$gate}", $email2email));
    sxtRun(['chown', 'root:postfix', SXT_RECEIVING]);
    sxtRun(['chmod', 'u=rw,g=r,o=', SXT_RECEIVING]);
    sxtRun(['postconf', '-e', 'virtual_alias_maps = mysql:/etc/postfix/mariadb-virtual-alias-maps.cf, mysql:'.SXT_RECEIVING]);

    file_put_contents(SXT_DOVECOT_SQL, str_replace(
        "FROM virtual_users WHERE email='%{user}'\n  iterate_query",
        "FROM virtual_users WHERE email='%{user}' \\\n    AND (enabled = 1 AND sendonly = 0 OR '%{protocol}' <> 'lmtp')\n  iterate_query",
        $dovecot,
    ));
    expect(file_get_contents(SXT_DOVECOT_SQL))->toContain("'%{protocol}' <> 'lmtp'");

    // The guide's submission service: SASL through Dovecot, and senders held to their own login names.
    file_put_contents(SXT_MASTER_CF, "\nsubmission inet n - y - - smtpd\n"
        ."  -o syslog_name=postfix/submission\n"
        ."  -o smtpd_tls_security_level=encrypt\n"
        ."  -o smtpd_sasl_auth_enable=yes\n"
        ."  -o smtpd_sasl_type=dovecot\n"
        ."  -o smtpd_sasl_path=private/dovecot-auth\n"
        ."  -o smtpd_tls_auth_only=yes\n"
        ."  -o smtpd_recipient_restrictions=\n"
        ."  -o smtpd_relay_restrictions=permit_sasl_authenticated,reject\n"
        ."  -o smtpd_sender_restrictions=reject_sender_login_mismatch\n", FILE_APPEND);
    sxtReload();
}

/** @param  array<string, string|null>  $snapshot */
function sxtRestore(array $snapshot): void
{
    foreach ($snapshot as $path => $content) {
        if ($content === null) {
            if (is_file($path)) {
                unlink($path);
            }
        } else {
            file_put_contents($path, $content);
        }
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

/**
 * Whether Postfix's submission service (587, STARTTLS, SASL PLAIN as $login) accepts a message from $from
 * for $to. A small SMTP client of its own, because the host's swaks has no TLS support.
 */
function sxtSubmit(string $login, string $to, ?string $from = null): bool
{
    $from ??= $login;
    $socket = stream_socket_client('tcp://127.0.0.1:587', $errno, $error, 5);
    expect($socket)->not->toBeFalse();
    stream_set_timeout($socket, 15);

    // Sends a command (null: just read), returns the final reply line of the answer.
    $say = function (?string $command) use ($socket): string {
        if ($command !== null) {
            fwrite($socket, $command."\r\n");
        }
        do {
            $line = (string) fgets($socket);
        } while (strlen($line) > 3 && $line[3] === '-');

        return trim($line);
    };

    try {
        $say(null);
        $say('EHLO localhost');
        if (! str_starts_with($say('STARTTLS'), '220')) {
            return false;
        }
        stream_context_set_option($socket, 'ssl', 'verify_peer', false);
        stream_context_set_option($socket, 'ssl', 'verify_peer_name', false);
        stream_context_set_option($socket, 'ssl', 'allow_self_signed', true);
        expect(stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT))->toBeTrue();
        $say('EHLO localhost');
        if (! str_starts_with($say('AUTH PLAIN '.base64_encode("\0{$login}\0summersun")), '235')) {
            return false;
        }
        if (! str_starts_with($say("MAIL FROM:<{$from}>"), '250')) {
            return false;
        }
        if (! str_starts_with($say("RCPT TO:<{$to}>"), '250')) {
            return false;
        }
        if (! str_starts_with($say('DATA'), '354')) {
            return false;
        }

        return str_starts_with($say("From: {$from}\r\nTo: {$to}\r\nSubject: sent by the owner\r\n\r\nHello.\r\n."), '250');
    } finally {
        fclose($socket);
    }
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

    $snapshot = sxtSnapshot();
    try {
        GuideFixture::addMailboxExtension();
        sxtApply();

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

        // ... and still sends as itself through authenticated submission (port 587, SASL, sender-login check),
        // to another local mailbox that receives the message.
        $friendLocal = apiLocal('fr');
        $friend = sxtMailbox($friendLocal);
        expect(sxtSubmit($address, $friend))->toBeTrue();
        expect(deliveredCount($friendLocal, 1))->toBe(1);
        // The sender-login map still ties the login to its own address: another From is refused.
        expect(sxtSubmit($address, $friend, 'someone.else@example.org'))->toBeFalse();

        // Back on: delivered again, both ways.
        DB::table(GuideSchema::table('virtual_users'))->where('email', $address)->update([$column => 1 - $off]);
        expect(sxtRcpt($address)['ok'])->toBeTrue();
        sendMail($address);
        sendMail($via);
        expect(deliveredCount($local, $before + 2))->toBe($before + 2);
    } finally {
        try {
            sxtRestore($snapshot);
        } finally {
            GuideFixture::dropMailboxExtension();
        }
    }
})->with([
    'receiving off (enabled = 0)' => ['enabled', 0],
    'send-only (sendonly = 1)' => ['sendonly', 1],
]);
