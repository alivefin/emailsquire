<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DovecotAuthClient;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

// Truncation instead of transactions: fixture DDL commits implicitly.
uses(TestCase::class, DatabaseTruncation::class)->in('Feature', 'MailTruth');
uses(TestCase::class)->in('Unit');

// Mail-truth tests talk to the real doveadm API in the container; TestCase prevents stray requests.
pest()->beforeEach(fn () => Http::allowStrayRequests())->in('MailTruth');

/**
 * Points every server check at paths, a socket and a doveadm URL that don't exist, so doctor
 * tests that run all checks never read the developer's own host. With $fakeDoveadm, doveadm
 * also answers as unreachable; leave it off where a test fakes doveadm itself, since a throwing
 * fake would run before (and break) any later one.
 */
function isolateDoctorFromHost(bool $fakeDoveadm = true): void
{
    $missing = '/nonexistent/emailsquire-test';

    config()->set([
        'emailsquire.paths.postfix_main_cf' => "{$missing}/main.cf",
        'emailsquire.paths.mail_root' => "{$missing}/vmail",
        'emailsquire.paths.trash_dir' => "{$missing}/vmail/.emailsquire-trash",
        'emailsquire.paths.dkim_dir' => "{$missing}/dkim",
        'emailsquire.paths.fpm_php_ini' => "{$missing}/php.ini",
        'emailsquire.dovecot_auth.socket' => "{$missing}/auth.sock",
        'emailsquire.doveadm.url' => 'http://doveadm.invalid/doveadm/v1',
    ]);
    app()->forgetInstance(DoveadmClient::class);
    app()->forgetInstance(DovecotAuthClient::class);

    if ($fakeDoveadm) {
        Http::fake(fn () => throw new ConnectionException('doveadm is not reachable in tests'));
    }
}
