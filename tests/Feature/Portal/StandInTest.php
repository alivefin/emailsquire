<?php

use App\Doctor\Checks\RuntimeTestEnvironment;
use App\Doctor\Status;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\FakeDovecotAuthClient;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\GuideSchema;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

// The e2e environment's Dovecot stand-in, and the doctor check that keeps test environments off a
// real mail server.

function siInEnvironment(string $env, Closure $callback): mixed
{
    $previous = app()['env'];
    app()['env'] = $env;
    try {
        return $callback();
    } finally {
        app()['env'] = $previous;
    }
}

/** What the container gives for PasswordChecker after AppServiceProvider registers in $env. */
function siBinding(string $env): PasswordChecker
{
    return siInEnvironment($env, function () {
        (new AppServiceProvider(app()))->register();
        app()->forgetInstance(PasswordChecker::class);

        return app(PasswordChecker::class);
    });
}

it('binds the Dovecot auth socket everywhere but e2e, and the stand-in only in e2e', function () {
    expect(siBinding('production'))->toBeInstanceOf(DovecotAuthClient::class)
        ->and(siBinding('local'))->toBeInstanceOf(DovecotAuthClient::class)
        ->and(siBinding('testing'))->toBeInstanceOf(DovecotAuthClient::class)
        ->and(siBinding('e2e'))->toBeInstanceOf(FakeDovecotAuthClient::class);
});

it('the stand-in checks the stored {BLF-CRYPT} hash, in e2e only', function () {
    GuideFixture::load('trixie');
    DB::table(GuideSchema::table('virtual_users'))->insert([
        ['domain_id' => 10, 'email' => 'anna@example.org', 'password' => '{BLF-CRYPT}'.password_hash('summersun', PASSWORD_BCRYPT, ['cost' => 4]), 'quota' => 0],
        ['domain_id' => 10, 'email' => 'Mixed@example.org', 'password' => '{BLF-CRYPT}'.password_hash('summersun', PASSWORD_BCRYPT, ['cost' => 4]), 'quota' => 0],
        ['domain_id' => 10, 'email' => 'old@example.org', 'password' => '{SHA512-CRYPT}'.crypt('summersun', '$6$saltsalt$'), 'quota' => 0],
        ['domain_id' => 10, 'email' => 'bare@example.org', 'password' => password_hash('summersun', PASSWORD_BCRYPT, ['cost' => 4]), 'quota' => 0],
    ]);
    $fake = new FakeDovecotAuthClient;

    siInEnvironment('e2e', function () use ($fake) {
        expect($fake->verify('anna@example.org', 'summersun', '127.0.0.1'))->toBeTrue()
            ->and($fake->verify('Mixed@example.org', 'summersun'))->toBeTrue()
            ->and($fake->verify('anna@example.org', 'wintermoon'))->toBeFalse()
            ->and($fake->verify('nobody@example.org', 'summersun'))->toBeFalse()
            // Only {BLF-CRYPT}: another scheme, or a hash without the prefix, is refused.
            ->and($fake->verify('old@example.org', 'summersun'))->toBeFalse()
            ->and($fake->verify('bare@example.org', 'summersun'))->toBeFalse();
    });

    expect(fn () => $fake->verify('anna@example.org', 'summersun'))->toThrow(LogicException::class);
});

it('the doctor fails a test environment pointed at the production mail root', function (string $env, string $root, Status $status) {
    config(['emailsquire.paths.mail_root' => $root]);

    $result = siInEnvironment($env, fn () => app(RuntimeTestEnvironment::class)->run());

    expect($result->status)->toBe($status);
    if ($status === Status::Fail) {
        expect($result->detail)->toContain("APP_ENV is {$env}")->toContain('/var/vmail');
    }
})->with([
    'e2e on /var/vmail' => ['e2e', '/var/vmail', Status::Fail],
    'e2e on /var/vmail/' => ['e2e', '/var/vmail/', Status::Fail],
    'testing on /var/vmail' => ['testing', '/var/vmail', Status::Fail],
    'e2e elsewhere' => ['e2e', '/tmp/e2e-vmail', Status::Ok],
    'production on /var/vmail' => ['production', '/var/vmail', Status::Ok],
]);
