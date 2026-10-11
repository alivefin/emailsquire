<?php

use App\Doctor\SmtpProbe;
use App\MailServer\Dns\DnsResolver;
use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\PasswordChecker;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use PragmaRX\Google2FA\Google2FA;
use Tests\Support\FakePasswordChecker;
use Tests\Support\StubDnsResolver;
use Tests\TestCase;

// Truncation instead of transactions: fixture DDL commits implicitly.
uses(TestCase::class, DatabaseTruncation::class)->in('Feature', 'MailTruth');
uses(TestCase::class)->in('Unit');

// Mail-truth tests talk to the real doveadm API in the container; TestCase prevents stray requests.
pest()->beforeEach(fn () => Http::allowStrayRequests())->in('MailTruth');

// Feature tests never reach a Dovecot auth socket: mailbox passwords are checked by a fake that
// accepts only what a test tells it to (app(PasswordChecker::class)->accept(...)). Mail truth uses the real one.
pest()->beforeEach(fn () => app()->instance(PasswordChecker::class, new FakePasswordChecker))->in('Feature');

// Nor a DNS resolver: every name answers like NXDOMAIN until a test sets records (app(DnsResolver::class)->set(...)).
pest()->beforeEach(fn () => app()->instance(DnsResolver::class, new StubDnsResolver))->in('Feature');

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
        'emailsquire.paths.dkim_selector_map' => "{$missing}/rspamd/emailsquire/dkim_selectors.map",
        'emailsquire.paths.rspamd_config_dir' => "{$missing}/rspamd",
        'emailsquire.paths.fpm_php_ini' => "{$missing}/php.ini",
        'emailsquire.dovecot_auth.socket' => "{$missing}/auth.sock",
        'emailsquire.doveadm.url' => 'http://doveadm.invalid/doveadm/v1',
    ]);
    app()->forgetInstance(DoveadmClient::class);
    app()->forgetInstance(DovecotAuthClient::class);
    // No real Postfix in tests: the SMTP check sees a greeting.
    app()->instance(SmtpProbe::class, new class extends SmtpProbe
    {
        public function banner(string $host, int $port, float $timeout = 3.0): ?string
        {
            return '220 test';
        }
    });

    if ($fakeDoveadm) {
        Http::fake(fn () => throw new ConnectionException('doveadm is not reachable in tests'));
    }
}

/**
 * Marks the session as freshly step-up confirmed (RequireStepUp), as a successful
 * POST /auth/confirm-password would; StepUpTest covers the real confirmation.
 */
function confirmStepUp(): void
{
    test()->withSession(['auth.password_confirmed_at' => now()->timestamp]);
}

/**
 * Inserts a `sessions` row as the database session driver would for a signed-in browser of $user
 * (or, with null, a browser nobody is signed in to yet, such as a pending two-factor sign-in).
 */
function insertSessionRow(?int $userId, string $id): void
{
    DB::table('sessions')->insert([
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '203.0.113.7',
        'user_agent' => 'test',
        'payload' => base64_encode(serialize([])),
        'last_activity' => now()->getTimestamp(),
    ]);
}

/** @return list<string> */
function sessionRowIds(): array
{
    return DB::table('sessions')->orderBy('id')->pluck('id')->all();
}

/** Confirmed two-factor with 8 fresh recovery codes; returns the plain secret. */
function tfaEnrol(User $user): string
{
    $secret = app(Google2FA::class)->generateSecretKey();
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all())),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $secret;
}

function tfaOtp(string $secret): string
{
    return app(Google2FA::class)->getCurrentOtp($secret);
}
