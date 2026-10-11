<?php

use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\DovecotAuthFailure;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\GuideSchema;
use App\Models\DisabledMailbox;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePasswordChecker;
use Tests\Support\GuideFixture;

// Sign-in routing (spec decision 1, plan Review Focus 1-3): one POST /auth/login for console users and
// mailbox owners. The console account is tried first; only when it does not sign in is the address
// tried as a mailbox, through Dovecot (faked here: FakePasswordChecker).

const PSI_CONSOLE = 'console passphrase 1';
const PSI_MAILBOX = 'mailbox passphrase 2';

beforeEach(function () {
    GuideFixture::load('trixie'); // example.org, id 10
    psiMailbox('anna@example.org');   // a mailbox only
    psiMailbox('boss@example.org');   // a mailbox and a console account
    psiMailbox('off@example.org');    // a disabled mailbox
    DisabledMailbox::query()->forceCreate([
        'mailbox_email' => 'off@example.org', 'domain_id' => 10, 'saved_password' => '{BLF-CRYPT}saved', 'disabled_at' => now(),
    ]);
    psiConsole('admin@example.org');  // a console account only
    psiConsole('boss@example.org');

    foreach (['anna@example.org', 'boss@example.org', 'off@example.org', 'gone@example.org'] as $address) {
        psiChecker()->accept($address, PSI_MAILBOX);
    }
});

function psiChecker(): FakePasswordChecker
{
    $checker = app(PasswordChecker::class);
    assert($checker instanceof FakePasswordChecker);

    return $checker;
}

function psiMailbox(string $email, int $quota = 0, string $password = '{BLF-CRYPT}$2y$12$fixturefixturefixturefixturefixturefixturefixturefixt'): void
{
    DB::table(GuideSchema::table('virtual_users'))->insert(['domain_id' => 10, 'email' => $email, 'password' => $password, 'quota' => $quota]);
}

function psiConsole(string $email, array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => $email, 'password' => PSI_CONSOLE, 'password_set_at' => now(), 'remember_token' => null,
    ], $attributes));
}

function psiLogin(string $email, string $password): TestResponse
{
    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password]);
}

/**
 * A request from the browser whose session cookie is $sessionId, on the database session driver: the
 * test client's signed-in guards and session attributes are dropped first, so only the cookie says
 * who it is (as in ChangePasswordTest).
 */
function psiAs(string $sessionId)
{
    app('auth')->forgetGuards();
    session()->flush();

    return test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

function psiCookie(TestResponse $response): string
{
    return $response->getCookie(config('session.cookie'))->getValue();
}

/** Signs in on the database session driver from a fresh browser and returns the session id. */
function psiSession(string $email, string $password): string
{
    return psiCookie(psiAs('')->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk());
}

// --- Routing -------------------------------------------------------------------------------------

it('routes each sign-in as decision 1 says', function (string $email, string $password, string $outcome) {
    $reference = psiLogin('nobody@example.org', 'whatever it is 1')->assertStatus(422)->getContent();

    $response = psiLogin($email, $password);

    match ($outcome) {
        'console' => $response->assertOk()->assertJsonPath('kind', 'console')->assertJsonPath('email', $email),
        'mailbox' => $response->assertOk()->assertExactJson(['kind' => 'mailbox']),
        'refused' => expect($response->status())->toBe(422)->and($response->getContent())->toBe($reference),
    };
    $this->getJson('/api/v1/me')->assertStatus($outcome === 'console' ? 200 : 401);
    $this->getJson('/api/v1/portal/me')->assertStatus($outcome === 'mailbox' ? 200 : 401);
})->with([
    'console only, console password' => ['admin@example.org', PSI_CONSOLE, 'console'],
    'console only, wrong password' => ['admin@example.org', 'wrong passphrase 9', 'refused'],
    'mailbox only, mailbox password' => ['anna@example.org', PSI_MAILBOX, 'mailbox'],
    'mailbox only, wrong password' => ['anna@example.org', 'wrong passphrase 9', 'refused'],
    'both, console password' => ['boss@example.org', PSI_CONSOLE, 'console'],
    'both, mailbox password' => ['boss@example.org', PSI_MAILBOX, 'mailbox'],
    'both, wrong password' => ['boss@example.org', 'wrong passphrase 9', 'refused'],
    'disabled mailbox, its password' => ['off@example.org', PSI_MAILBOX, 'refused'],
    'deleted mailbox, its old password' => ['gone@example.org', PSI_MAILBOX, 'refused'],
    'unknown address' => ['nobody@example.org', PSI_MAILBOX, 'refused'],
    'unknown address on a domain not hosted here' => ['nobody@elsewhere.test', PSI_MAILBOX, 'refused'],
]);

it('an address that is both gets the two-factor step with the console password and the portal without it with the mailbox password', function () {
    tfaEnrol(User::query()->where('email', 'boss@example.org')->firstOrFail());

    psiLogin('boss@example.org', PSI_CONSOLE)->assertOk()->assertExactJson(['two_factor' => true]);
    expect(psiChecker()->calls)->toBe([]);

    psiLogin('boss@example.org', PSI_MAILBOX)->assertOk()->assertExactJson(['kind' => 'mailbox']);
    expect(session()->has('login.id'))->toBeFalse();
    $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', 'boss@example.org');
    $this->getJson('/api/v1/me')->assertUnauthorized();
});

it('every refusal is the same 422 body, whatever exists', function () {
    $bodies = [];
    foreach ([
        'unknown' => ['nobody@example.org', 'whatever it is 1'],
        'console, wrong password' => ['admin@example.org', 'whatever it is 1'],
        'mailbox, wrong password' => ['anna@example.org', 'whatever it is 1'],
        'both, wrong password' => ['boss@example.org', 'whatever it is 1'],
        'disabled, right password' => ['off@example.org', PSI_MAILBOX],
        'deleted, old password' => ['gone@example.org', PSI_MAILBOX],
    ] as $case => [$email, $password]) {
        $bodies[$case] = psiLogin($email, $password)->assertStatus(422)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('code', 'validation_failed')
            ->assertJsonPath('errors.email', ['Email or password is wrong.'])
            ->getContent();
    }

    expect(array_unique($bodies))->toHaveCount(1);
});

it('every refusal on a hosted domain asks Dovecot once, so none is answered faster than a wrong mailbox password', function () {
    // Dovecot answers any failure after auth_failure_delay (2 s): asking it for every hosted address,
    // mailbox or not, keeps "is this a mailbox" out of the response time.
    foreach (['nobody@example.org', 'admin@example.org', 'anna@example.org', 'off@example.org', 'gone@example.org'] as $email) {
        psiChecker()->calls = [];
        psiLogin($email, 'whatever it is 1')->assertStatus(422);

        expect(psiChecker()->calls)->toBe([['user' => $email, 'ip' => '127.0.0.1']]);
    }

    // Which domains are hosted is public (MX records); an address elsewhere is not a mailbox here.
    psiChecker()->calls = [];
    psiLogin('nobody@elsewhere.test', 'whatever it is 1')->assertStatus(422);
    psiLogin('not an address', 'whatever it is 1')->assertStatus(422);
    expect(psiChecker()->calls)->toBe([]);
});

it('a console sign-in never asks Dovecot', function () {
    psiLogin('admin@example.org', PSI_CONSOLE)->assertOk();
    psiLogin('boss@example.org', PSI_CONSOLE)->assertOk();

    expect(psiChecker()->calls)->toBe([]);
});

it('asks Dovecot with the stored spelling and the client IP', function () {
    psiMailbox('Mixed@example.org');
    psiChecker()->accept('Mixed@example.org', PSI_MAILBOX);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', ['email' => 'mixed@EXAMPLE.org', 'password' => PSI_MAILBOX])
        ->assertOk()->assertExactJson(['kind' => 'mailbox']);

    expect(psiChecker()->calls)->toBe([['user' => 'Mixed@example.org', 'ip' => '203.0.113.9']]);
    $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', 'Mixed@example.org');
});

it('fails closed with the same 422 when the auth socket cannot be reached', function () {
    $reference = psiLogin('nobody@elsewhere.test', 'whatever it is 1')->getContent();
    psiChecker()->failWith(new DovecotAuthException(DovecotAuthFailure::Unreachable, 'no socket'));

    $response = psiLogin('anna@example.org', PSI_MAILBOX);

    expect($response->status())->toBe(422)->and($response->getContent())->toBe($reference);
    $this->getJson('/api/v1/portal/me')->assertUnauthorized();
});

it('logs a refused mailbox sign-in for fail2ban with kind=mailbox, never the password', function () {
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure kind=mailbox user=anna@example.org ip=127.0.0.1');

    psiLogin('anna@example.org', 'whatever it is 1')->assertStatus(422);
});

it('logs a refused sign-in of an address that is no mailbox with kind=console', function () {
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure kind=console user=nobody@example.org ip=127.0.0.1');

    psiLogin('nobody@example.org', 'whatever it is 1')->assertStatus(422);
});

it('a mailbox sign-in never sets a remember-me cookie', function () {
    $response = $this->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => PSI_MAILBOX, 'remember' => true])->assertOk();

    foreach ($response->headers->getCookies() as $cookie) {
        expect($cookie->getName())->not->toStartWith('remember_');
    }
});

// --- Throttle ------------------------------------------------------------------------------------

it('mailbox-login allows 5 mailbox attempts a minute per address and IP, and a console sign-in does not reset it', function () {
    foreach (range(1, 3) as $_) {
        psiLogin('boss@example.org', 'whatever it is 1')->assertStatus(422);
    }
    // The console password clears `login` (LoginThrottle), but not the mailbox attempts.
    psiLogin('boss@example.org', PSI_CONSOLE)->assertOk();
    foreach (range(1, 2) as $_) {
        psiLogin('boss@example.org', 'whatever it is 1')->assertStatus(422);
    }

    $response = psiLogin('boss@example.org', PSI_MAILBOX);

    $response->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'too_many_requests');
    expect($response->headers->get('Retry-After'))->not->toBeNull();
    // The sixth mailbox attempt never reached Dovecot.
    expect(psiChecker()->calls)->toHaveCount(5);
    // The console password still works: the mailbox limit guards only the mailbox check.
    psiLogin('boss@example.org', PSI_CONSOLE)->assertOk()->assertJsonPath('kind', 'console');
});

it('the sixth sign-in attempt of a mailbox in a minute is 429', function () {
    foreach (range(1, 5) as $_) {
        psiLogin('anna@example.org', 'whatever it is 1')->assertStatus(422);
    }

    psiLogin('anna@example.org', PSI_MAILBOX)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

it('a mailbox sign-in does not clear the console limit, so a known mail password buys no console guesses', function () {
    foreach (range(1, 4) as $_) {
        psiLogin('boss@example.org', 'whatever it is 1')->assertStatus(422);
    }
    psiLogin('boss@example.org', PSI_MAILBOX)->assertOk()->assertExactJson(['kind' => 'mailbox']);

    psiLogin('boss@example.org', 'whatever it is 1')->assertStatus(429);
});

// --- One guard per session -----------------------------------------------------------------------

it('a console session that signs in as a mailbox loses its console user, on a new session id', function () {
    config(['session.driver' => 'database']);
    $console = psiSession('admin@example.org', PSI_CONSOLE);
    psiAs($console)->getJson('/api/v1/me')->assertOk();

    $portal = psiCookie(psiAs($console)->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => PSI_MAILBOX])
        ->assertOk()->assertExactJson(['kind' => 'mailbox']));

    expect($portal)->not->toBe($console);
    psiAs($portal)->getJson('/api/v1/me')->assertUnauthorized();
    psiAs($portal)->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', 'anna@example.org');
    psiAs($console)->getJson('/api/v1/me')->assertUnauthorized();
    expect(DB::table('sessions')->where('id', $portal)->value('user_id'))->toBeNull();
});

it('a portal session that signs in to the console loses its mailbox, on a new session id', function () {
    config(['session.driver' => 'database']);
    $portal = psiSession('anna@example.org', PSI_MAILBOX);
    psiAs($portal)->getJson('/api/v1/portal/me')->assertOk();

    $console = psiCookie(psiAs($portal)->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => PSI_CONSOLE])
        ->assertOk()->assertJsonPath('kind', 'console'));

    expect($console)->not->toBe($portal);
    psiAs($console)->getJson('/api/v1/portal/me')->assertUnauthorized();
    psiAs($console)->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'admin@example.org');
    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

it('a mailbox sign-in drops a step-up window and a pending two-factor sign-in left in the session', function () {
    $this->withSession(['auth.password_confirmed_at' => now()->timestamp, 'login.id' => 1, 'login.remember' => false, 'login.at' => now()->timestamp]);

    psiLogin('anna@example.org', PSI_MAILBOX)->assertOk();

    expect(session()->has('auth.password_confirmed_at'))->toBeFalse()
        ->and(session()->has('login.id'))->toBeFalse()
        ->and(session('auth.signed_in_at'))->toBeInt();
});

it('the session row of a portal session names the mailbox, and a console one names only the user', function () {
    config(['session.driver' => 'database']);
    psiMailbox('Mixed@example.org');
    psiChecker()->accept('Mixed@example.org', PSI_MAILBOX);

    $portal = psiSession('mixed@example.org', PSI_MAILBOX);
    psiAs($portal)->getJson('/api/v1/portal/me')->assertOk();
    $console = psiSession('admin@example.org', PSI_CONSOLE);
    psiAs($console)->getJson('/api/v1/me')->assertOk();

    expect((array) DB::table('sessions')->where('id', $portal)->first(['user_id', 'mailbox_address']))
        ->toBe(['user_id' => null, 'mailbox_address' => 'Mixed@example.org'])
        ->and((array) DB::table('sessions')->where('id', $console)->first(['user_id', 'mailbox_address']))
        ->toBe(['user_id' => User::query()->where('email', 'admin@example.org')->value('id'), 'mailbox_address' => null]);
});

it('sign-out ends a portal session', function () {
    psiLogin('anna@example.org', PSI_MAILBOX)->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

// --- Separation ----------------------------------------------------------------------------------

it('a console session gets 401 on the portal and keeps its console session', function () {
    config(['session.driver' => 'database']);
    $console = psiSession('boss@example.org', PSI_CONSOLE);

    psiAs($console)->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    psiAs($console)->getJson('/api/v1/me')->assertOk();
});

it('a portal session gets 401 on the console and keeps its portal session', function () {
    config(['session.driver' => 'database']);
    $portal = psiSession('boss@example.org', PSI_MAILBOX);

    psiAs($portal)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    psiAs($portal)->getJson('/api/v1/domains')->assertUnauthorized();
    psiAs($portal)->postJson('/api/v1/auth/confirm-password', ['password' => PSI_MAILBOX])->assertUnauthorized();
    psiAs($portal)->getJson('/api/v1/portal/me')->assertOk();
});

// --- Mid-session ---------------------------------------------------------------------------------

it('a mailbox disabled while signed in answers 401 at the next request and its session is invalidated', function () {
    config(['session.driver' => 'database']);
    $portal = psiSession('anna@example.org', PSI_MAILBOX);
    psiAs($portal)->getJson('/api/v1/portal/me')->assertOk();

    // Only the disabled row: the provider refuses it even before the hash is swapped.
    DisabledMailbox::query()->forceCreate([
        'mailbox_email' => 'anna@example.org', 'domain_id' => 10, 'saved_password' => '{BLF-CRYPT}saved', 'disabled_at' => now(),
    ]);

    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated');
    expect(DB::table('sessions')->where('id', $portal)->exists())->toBeFalse();

    // Enabled again, the old session still does not come back.
    DisabledMailbox::query()->whereKey('anna@example.org')->delete();
    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

it('a mailbox deleted while signed in answers 401 at the next request and its session is invalidated', function () {
    config(['session.driver' => 'database']);
    $portal = psiSession('anna@example.org', PSI_MAILBOX);

    DB::table(GuideSchema::table('virtual_users'))->where('email', 'anna@example.org')->delete();

    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    expect(DB::table('sessions')->where('id', $portal)->exists())->toBeFalse();

    psiMailbox('anna@example.org'); // a new mailbox under the same address is not the one signed in
    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

it('a password changed in the console ends portal sessions at their next request', function () {
    config(['session.driver' => 'database']);
    $portal = psiSession('anna@example.org', PSI_MAILBOX);
    psiAs($portal)->getJson('/api/v1/portal/me')->assertOk();

    DB::table(GuideSchema::table('virtual_users'))->where('email', 'anna@example.org')
        ->update(['password' => '{BLF-CRYPT}'.password_hash('a new mail password 3', PASSWORD_BCRYPT, ['cost' => 4])]);

    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

// --- Session limits ------------------------------------------------------------------------------

it('a portal session ends 2 hours after sign-in however active it is, a console session does not', function () {
    // Frozen, so the sign-in stamp and the travels share one clock (else a second boundary between them flakes).
    $this->freezeTime();
    config(['session.driver' => 'database']);
    $portal = psiSession('anna@example.org', PSI_MAILBOX);
    $console = psiSession('admin@example.org', PSI_CONSOLE);

    // Active every 20 minutes, so the 30-minute idle limit never applies.
    foreach (range(1, 6) as $_) {
        $this->travel(20)->minutes();
        psiAs($portal)->getJson('/api/v1/portal/me')->assertOk();
        psiAs($console)->getJson('/api/v1/me')->assertOk();
    }
    $this->travel(1)->minutes(); // 2 h 1 min after sign-in

    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'session_expired');
    psiAs($console)->getJson('/api/v1/me')->assertOk();
    psiAs($portal)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

// --- Hardening (review round 1) ------------------------------------------------------------------

it('mailbox-login also caps one IP at 30 mailbox checks a minute across addresses, and another IP is unaffected', function () {
    foreach (range(1, 30) as $i) {
        psiLogin("random{$i}@example.org", 'whatever it is 1')->assertStatus(422);
    }

    psiLogin('random31@example.org', 'whatever it is 1')->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    expect(psiChecker()->calls)->toHaveCount(30);

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', ['email' => 'random32@example.org', 'password' => 'whatever it is 1'])
        ->assertStatus(422);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])
        ->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => PSI_MAILBOX])
        ->assertOk()->assertExactJson(['kind' => 'mailbox']);
});

it('counts the Unicode and punycode spellings of one address in one mailbox-login bucket', function () {
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'xn--exmple-cua.fi']);

    foreach (range(1, 3) as $_) {
        psiLogin('eero@exämple.fi', 'whatever it is 1')->assertStatus(422);
    }
    foreach (range(1, 2) as $_) {
        psiLogin('eero@xn--exmple-cua.fi', 'whatever it is 1')->assertStatus(422);
    }

    // `login` has seen this spelling only twice; the mailbox bucket has seen the address five times.
    psiLogin('eero@xn--exmple-cua.fi', 'whatever it is 1')->assertStatus(429);
});

it('an IDN address typed in Unicode reaches Dovecot as the stored punycode spelling', function () {
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'xn--exmple-cua.fi']);
    DB::table(GuideSchema::table('virtual_users'))->insert(['domain_id' => 11, 'email' => 'eero@xn--exmple-cua.fi', 'password' => '{BLF-CRYPT}x', 'quota' => 0]);
    psiChecker()->accept('eero@xn--exmple-cua.fi', PSI_MAILBOX);

    psiLogin('Eero@Exämple.fi', PSI_MAILBOX)->assertOk()->assertExactJson(['kind' => 'mailbox']);

    expect(psiChecker()->users())->toBe(['eero@xn--exmple-cua.fi']);
    $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', 'eero@xn--exmple-cua.fi');
});

it('a console sign-in from a portal session still works while the guide is unreadable', function () {
    config(['session.driver' => 'database']);
    // A server admin: a manager's `me` payload reads the guide itself (its landing domain).
    User::query()->where('email', 'admin@example.org')->update(['is_server_admin' => true]);
    $portal = psiSession('anna@example.org', PSI_MAILBOX);

    GuideFixture::drop();

    $console = psiCookie(psiAs($portal)->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => PSI_CONSOLE])
        ->assertOk()->assertJsonPath('kind', 'console'));

    psiAs($console)->getJson('/api/v1/me')->assertOk();
    expect((array) DB::table('sessions')->where('id', $console)->first(['user_id', 'mailbox_address']))
        ->toBe(['user_id' => User::query()->where('email', 'admin@example.org')->value('id'), 'mailbox_address' => null]);
});

it('a console sign-in that stops at the two-factor step already ends the portal session', function () {
    config(['session.driver' => 'database']);
    tfaEnrol(User::query()->where('email', 'boss@example.org')->firstOrFail());
    $portal = psiSession('anna@example.org', PSI_MAILBOX);

    $pending = psiCookie(psiAs($portal)->postJson('/api/v1/auth/login', ['email' => 'boss@example.org', 'password' => PSI_CONSOLE])
        ->assertOk()->assertExactJson(['two_factor' => true]));

    psiAs($pending)->getJson('/api/v1/portal/me')->assertUnauthorized();
    psiAs($pending)->getJson('/api/v1/me')->assertUnauthorized();
    expect(DB::table('sessions')->where('id', $pending)->value('mailbox_address'))->toBeNull();
});

it('a portal session still saves while the guide is unreadable', function () {
    config(['session.driver' => 'database']);
    $portal = psiSession('anna@example.org', PSI_MAILBOX);

    GuideFixture::drop();

    psiAs($portal)->getJson('/api/v1/auth/csrf')->assertSuccessful();
    expect(DB::table('sessions')->where('id', $portal)->value('mailbox_address'))->toBe('anna@example.org');
});
