<?php

use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\GuideSchema;
use App\Models\DomainSetting;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

// The mailbox portal against the real Dovecot and Postfix on the Trixie host (phase 3 spec, Testing):
// sign-in through the auth socket, a portal password change that IMAP sees at once, and an alias added
// in the portal that Postfix delivers. The Feature tests fake Dovecot (FakePasswordChecker); these don't.

pest()->group('mailtruth');

const PTL_PASSWORD = 'Portal-Summersun-2026!';
const PTL_NEW_PASSWORD = 'Wintertide-Portal-2027?';

beforeEach(function () {
    GuideFixture::load('trixie');

    // The owners' domain lets them manage aliases (the column default is off).
    DomainSetting::query()->forceCreate([
        'domain_id' => 10, 'domain_name' => 'example.org',
        'allow_user_forwarding' => true, 'allow_user_aliases' => true, 'allow_external_forwarding' => false,
    ]);
});

/** A fresh local part on example.org whose mailbox has $password, stored as the app stores it. */
function ptlMailbox(string $prefix, string $password = PTL_PASSWORD): string
{
    $local = apiLocal($prefix);
    insertMailbox("{$local}@example.org", '{BLF-CRYPT}'.password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]));

    return $local;
}

function ptlAuth(): DovecotAuthClient
{
    return new DovecotAuthClient((string) config('emailsquire.dovecot_auth.socket'), 30);
}

/**
 * Starts $ip's auth-penalty bucket from zero: a success resets it (see DovecotTest). Each test signs in
 * from its own documentation IP, so a failure in one never delays another, in this run or the next.
 */
function ptlResetPenalty(string $ip): void
{
    expect(ptlAuth()->verify('john@example.org', 'summersun', $ip))->toBeTrue();
}

/** POST /auth/login from $ip as a browser would, with no session or guard left from a previous request. */
function ptlSignIn(string $address, string $password, string $ip): TestResponse
{
    app('auth')->forgetGuards();
    test()->flushSession();

    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('/api/v1/auth/login', ['email' => $address, 'password' => $password]);
}

/** Whether IMAP LOGIN on port 143 accepts $address with $password (Dovecot's own login, not the app). */
function ptlImapLogin(string $address, string $password): bool
{
    $socket = stream_socket_client('tcp://127.0.0.1:143', $errno, $error, 5);
    expect($socket)->not->toBeFalse();
    // A failed LOGIN answers after auth_failure_delay, plus any penalty the 127.0.0.1 bucket carries.
    stream_set_timeout($socket, 30);
    fgets($socket); // greeting
    fwrite($socket, 'a1 LOGIN "'.$address.'" "'.$password.'"'."\r\n");
    $reply = '';
    while (($line = fgets($socket)) !== false) {
        $reply .= $line;
        if (str_starts_with($line, 'a1 ')) {
            break;
        }
    }
    fwrite($socket, "a2 LOGOUT\r\n");
    fclose($socket);

    expect($reply)->toMatch('/^a1 (OK|NO)/m');

    return str_contains($reply, 'a1 OK');
}

/**
 * The lines an IMAP server sends on $socket up to the reply tagged $tag, or until it closes the
 * connection or stays quiet for the socket's timeout.
 *
 * @param  resource  $socket
 */
function ptlImapReply($socket, string $tag): string
{
    $reply = '';
    while (($line = fgets($socket)) !== false) {
        $reply .= $line;
        if (str_starts_with($line, $tag.' ')) {
            break;
        }
    }

    return $reply;
}

/** Whether `doveadm who` lists $address after waiting up to 5 seconds for it to be $listed. */
function ptlWaitForWho(string $address, bool $listed): bool
{
    $who = function () use ($address): bool {
        $process = new Process(['doveadm', 'who']);
        $process->run();

        return str_contains($process->getOutput(), $address);
    };
    $deadline = microtime(true) + 5;
    while ($who() !== $listed && microtime(true) < $deadline) {
        usleep(200_000);
    }

    return $who();
}

it('signs a mailbox owner in to the portal with the mail password and refuses a wrong one', function () {
    $local = ptlMailbox('portal');
    $address = "{$local}@example.org";
    ptlResetPenalty('192.0.2.81');

    ptlSignIn($address, 'not the mail password', '192.0.2.81')
        ->assertUnprocessable()
        ->assertJsonPath('errors.email.0', trans('auth.failed'));
    $this->getJson('/api/v1/portal/me')->assertUnauthorized();

    ptlSignIn($address, PTL_PASSWORD, '192.0.2.81')->assertOk()->assertExactJson(['kind' => 'mailbox']);
    $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', $address);
    // A portal session is not a console session.
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('makes a portal password change work for IMAP at once and the old password stop working', function () {
    $local = ptlMailbox('pwchange');
    $address = "{$local}@example.org";
    ptlResetPenalty('192.0.2.82');

    ptlSignIn($address, PTL_PASSWORD, '192.0.2.82')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.82'])->putJson('/api/v1/portal/password', [
        'current_password' => PTL_PASSWORD,
        'password' => PTL_NEW_PASSWORD,
        'password_confirmation' => PTL_NEW_PASSWORD,
    ])->assertNoContent();

    $stored = DB::table(GuideSchema::table('virtual_users'))->where('email', $address)->value('password');
    expect($stored)->toStartWith('{BLF-CRYPT}$2y$12$');

    // The success first: it also resets the 127.0.0.1 bucket that the failure then charges.
    expect(ptlImapLogin($address, PTL_NEW_PASSWORD))->toBeTrue()
        ->and(ptlImapLogin($address, PTL_PASSWORD))->toBeFalse();
});

it('kicks an open IMAP session when the owner changes the password in the portal', function () {
    $local = ptlMailbox('pwkick');
    $address = "{$local}@example.org";
    ptlResetPenalty('192.0.2.84');

    $socket = stream_socket_client('tcp://127.0.0.1:143', $errno, $error, 5);
    expect($socket)->not->toBeFalse();
    stream_set_timeout($socket, 5);
    fgets($socket); // greeting
    fwrite($socket, 'a1 LOGIN "'.$address.'" "'.PTL_PASSWORD.'"'."\r\n");
    expect(ptlImapReply($socket, 'a1'))->toContain('a1 OK');
    // Dovecot lists the session once its login has finished; a kick before that has nothing to end.
    expect(ptlWaitForWho($address, true))->toBeTrue();

    ptlSignIn($address, PTL_PASSWORD, '192.0.2.84')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.84'])->putJson('/api/v1/portal/password', [
        'current_password' => PTL_PASSWORD,
        'password' => PTL_NEW_PASSWORD,
        'password_confirmation' => PTL_NEW_PASSWORD,
    ])->assertNoContent();

    expect(ptlWaitForWho($address, false))->toBeFalse();
    // The kicked connection is closed: its next command gets no OK (a BYE, or nothing at all).
    @fwrite($socket, "a2 NOOP\r\n");
    $reply = ptlImapReply($socket, 'a2');
    fclose($socket);

    expect($reply)->not->toContain('a2 OK');
});

it('delivers mail sent to an alias the owner added in the portal', function () {
    $local = ptlMailbox('aliasowner');
    $alias = apiLocal('alias');
    ptlResetPenalty('192.0.2.83');

    ptlSignIn("{$local}@example.org", PTL_PASSWORD, '192.0.2.83')->assertOk();
    $this->postJson('/api/v1/portal/aliases', ['local' => $alias])->assertCreated();

    expect(postmapQuery("{$alias}@example.org", 'virtual-alias-maps'))->toBe("{$local}@example.org");

    sendMail("{$alias}@example.org");

    expect(deliveredCount($local, 1))->toBe(1);
});

it('answers an unknown user and a wrong password after the same failure delay', function () {
    // SignInRouting asks Dovecot about every address on a hosted domain, mailbox or not, so that the
    // refusal's timing doesn't tell whether a mailbox exists. That holds only while Dovecot pays
    // auth_failure_delay (2 s) for both. Each sample comes from a fresh penalty bucket.
    $measure = function (string $user, string $password, string $ip): float {
        ptlResetPenalty($ip);
        $start = microtime(true);
        expect(ptlAuth()->verify($user, $password, $ip))->toBeFalse();

        return microtime(true) - $start;
    };

    $unknown = [];
    $wrong = [];
    foreach ([1, 2, 3] as $i) {
        $unknown[] = $measure('nobody-'.bin2hex(random_bytes(3)).'@example.org', 'summersun', "192.0.2.9{$i}");
        $wrong[] = $measure('john@example.org', 'wrong-'.bin2hex(random_bytes(6)), "192.0.2.10{$i}");
    }

    if (getenv('MAILTRUTH_TIMINGS') !== false) {
        fwrite(STDERR, sprintf("\nunknown user: %s s\nwrong password: %s s\n",
            implode(', ', array_map(fn (float $s) => number_format($s, 3), $unknown)),
            implode(', ', array_map(fn (float $s) => number_format($s, 3), $wrong)),
        ));
    }

    foreach ([...$unknown, ...$wrong] as $seconds) {
        expect($seconds)->toBeGreaterThanOrEqual(1.5, sprintf(
            'Dovecot refused in %.3f s: is auth_failure_delay still 2 s on this host? Without it a refusal\'s timing tells which addresses are mailboxes.',
            $seconds,
        ));
    }
    $mean = fn (array $xs): float => array_sum($xs) / count($xs);
    expect(abs($mean($unknown) - $mean($wrong)))->toBeLessThan(1.0, sprintf(
        'An unknown user (mean %.3f s) and a wrong password (mean %.3f s) should both take auth_failure_delay.',
        $mean($unknown),
        $mean($wrong),
    ));
});
