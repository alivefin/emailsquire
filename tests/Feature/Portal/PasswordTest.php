<?php

use App\MailServer\Dovecot\DovecotAuthException;
use App\MailServer\Dovecot\DovecotAuthFailure;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\GuideSchema;
use App\MailServer\MailboxSessions;
use App\MailServer\Models\Mailbox;
use App\MailServer\Repositories\MailboxRepository;
use App\Models\AuditEntry;
use App\Models\DisabledMailbox;
use App\Models\DomainGrant;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Tests\Support\FakePasswordChecker;
use Tests\Support\GuideFixture;

// PUT /portal/password (spec decision 8, plan Task 3 and Review Focus 5): the owner changes their own
// mailbox password. The current one is checked by Dovecot (faked: FakePasswordChecker), the new one
// follows the mailbox rules and is stored as {BLF-CRYPT} with cost 12. Other IMAP sessions are kicked
// and the mailbox's other portal sessions end; this one stays signed in on a new session id.

const PPW_OLD = 'old mail passphrase 1';
const PPW_NEW = 'a brand new mail passphrase 7';

beforeEach(function () {
    GuideFixture::load('trixie'); // example.org, id 10
    ppwMailbox('anna@example.org', PPW_OLD);
    ppwMailbox('bob@example.org', PPW_OLD);
    ppwChecker()->accept('anna@example.org', PPW_OLD)->accept('bob@example.org', PPW_OLD);
    $this->kicked = [];
    Http::fake(function (HttpRequest $request) {
        if (str_contains($request->url(), 'pwnedpasswords')) {
            return null; // the HIBP test's own fake answers
        }
        $call = $request->data()[0];
        $this->kicked[] = $call[1]['mask'];

        return Http::response([['doveadmResponse', [], $call[2]]]);
    });
});

function ppwChecker(): FakePasswordChecker
{
    $checker = app(PasswordChecker::class);
    assert($checker instanceof FakePasswordChecker);

    return $checker;
}

function ppwMailbox(string $email, string $password): void
{
    DB::table(GuideSchema::table('virtual_users'))->insert([
        'domain_id' => 10, 'email' => $email, 'quota' => 0,
        'password' => '{BLF-CRYPT}'.password_hash($password, PASSWORD_BCRYPT, ['cost' => 4]),
    ]);
}

function ppwHash(string $email): string
{
    return (string) DB::table(GuideSchema::table('virtual_users'))->where('email', $email)->value('password');
}

/** @param  array<string, mixed>  $over */
function ppwBody(array $over = []): array
{
    return array_merge(['current_password' => PPW_OLD, 'password' => PPW_NEW, 'password_confirmation' => PPW_NEW], $over);
}

/** A request from the browser whose session cookie is $sessionId (database session driver; as in Portal\SignInTest). */
function ppwAs(string $sessionId)
{
    app('auth')->forgetGuards();
    session()->flush();

    return test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

function ppwSession(string $email, string $password = PPW_OLD): string
{
    return ppwCookie(ppwAs('')->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password])->assertOk()->assertExactJson(['kind' => 'mailbox']));
}

function ppwCookie(TestResponse $response): string
{
    return $response->getCookie(config('session.cookie'))->getValue();
}

function ppwSignIn(string $email = 'anna@example.org'): void
{
    test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => PPW_OLD])->assertOk()->assertExactJson(['kind' => 'mailbox']);
}

function ppwPut(array $over = []): TestResponse
{
    return test()->putJson('/api/v1/portal/password', ppwBody($over));
}

// --- The change ----------------------------------------------------------------------------------

it('stores a {BLF-CRYPT} cost-12 hash that matches the new password and not the old one', function () {
    ppwSignIn();
    ppwChecker()->calls = [];

    ppwPut()->assertNoContent();

    $stored = ppwHash('anna@example.org');
    expect($stored)->toStartWith('{BLF-CRYPT}$2y$12$')
        ->and(password_verify(PPW_NEW, substr($stored, strlen('{BLF-CRYPT}'))))->toBeTrue()
        ->and(password_verify(PPW_OLD, substr($stored, strlen('{BLF-CRYPT}'))))->toBeFalse()
        // The current password was asked of Dovecot, for this mailbox, from the client's IP.
        ->and(ppwChecker()->calls)->toBe([['user' => 'anna@example.org', 'ip' => '127.0.0.1']])
        // Nobody else's password moved.
        ->and(password_verify(PPW_OLD, substr(ppwHash('bob@example.org'), strlen('{BLF-CRYPT}'))))->toBeTrue();
});

it('kicks the IMAP sessions, ends the other portal sessions and keeps this one on a new session id', function () {
    config(['session.driver' => 'database']);
    $current = ppwSession('anna@example.org');
    $phone = ppwSession('anna@example.org');
    $laptop = ppwSession('anna@example.org');
    $bob = ppwSession('bob@example.org');
    insertSessionRow(User::factory()->create()->id, 'console-browser');
    expect(DB::table('sessions')->where('mailbox_address', 'anna@example.org')->count())->toBe(3);

    $response = ppwAs($current)->putJson('/api/v1/portal/password', ppwBody())->assertNoContent();
    $renewed = ppwCookie($response);

    expect($renewed)->not->toBe($current)
        ->and($this->kicked)->toBe([['anna@example.org']])
        ->and(sessionRowIds())->toEqualCanonicalizing([$renewed, $bob, 'console-browser'])
        ->and(DB::table('sessions')->where('id', $renewed)->value('mailbox_address'))->toBe('anna@example.org');

    // This browser keeps working, at its next requests too; the old id and the others are signed out.
    ppwAs($renewed)->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', 'anna@example.org');
    ppwAs($renewed)->getJson('/api/v1/portal/me')->assertOk();
    foreach ([$current, $phone, $laptop] as $ended) {
        ppwAs($ended)->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    }
    ppwAs($bob)->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', 'bob@example.org');
});

it('a portal session that kept its row is still ended by the changed hash', function () {
    config(['session.driver' => 'database']);
    $current = ppwSession('anna@example.org');
    $other = ppwSession('anna@example.org');
    // As if the other row had been written under no mailbox_address (say, mid-request during the change).
    DB::table('sessions')->where('id', $other)->update(['mailbox_address' => null]);

    ppwAs($current)->putJson('/api/v1/portal/password', ppwBody())->assertNoContent();

    expect(DB::table('sessions')->where('id', $other)->exists())->toBeTrue();
    ppwAs($other)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

it('signs in with the new password afterwards (as Dovecot would accept it)', function () {
    config(['session.driver' => 'database']);
    $current = ppwSession('anna@example.org');

    ppwAs($current)->putJson('/api/v1/portal/password', ppwBody())->assertNoContent();

    // The fake checker stands in for Dovecot: teach it what the stored hash now accepts.
    $stored = substr(ppwHash('anna@example.org'), strlen('{BLF-CRYPT}'));
    app()->instance(PasswordChecker::class, (new FakePasswordChecker)->accept('anna@example.org', password_verify(PPW_NEW, $stored) ? PPW_NEW : 'nope'));
    ppwSession('anna@example.org', PPW_NEW);
});

it('works on a mailbox stored in mixed case, under its stored spelling', function () {
    config(['session.driver' => 'database']);
    ppwMailbox('Mixed@example.org', PPW_OLD);
    ppwChecker()->accept('Mixed@example.org', PPW_OLD);
    $current = ppwSession('mixed@example.org');
    $other = ppwSession('mixed@example.org');

    $renewed = ppwCookie(ppwAs($current)->putJson('/api/v1/portal/password', ppwBody())->assertNoContent());

    expect(ppwHash('Mixed@example.org'))->toStartWith('{BLF-CRYPT}$2y$12$')
        ->and($this->kicked)->toBe([['Mixed@example.org']])
        ->and(DB::table('sessions')->where('id', $other)->exists())->toBeFalse()
        ->and(AuditEntry::query()->sole()->actor_id)->toBe('Mixed@example.org');
    ppwAs($renewed)->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('address', 'Mixed@example.org');
});

// --- Audit ---------------------------------------------------------------------------------------

it('audits portal.password_changed with the mailbox as actor and no changes', function () {
    ppwSignIn();

    ppwPut()->assertNoContent();

    $audit = AuditEntry::query()->sole();
    expect($audit->action)->toBe('portal.password_changed')
        ->and($audit->actor_type)->toBe('mailbox')
        ->and($audit->actor_id)->toBe('anna@example.org')
        ->and($audit->object_type)->toBe('mailbox')
        ->and($audit->object_key)->toBe('anna@example.org')
        ->and($audit->domain_name)->toBe('example.org')
        ->and($audit->changes)->toBeNull()
        ->and($audit->ip)->toBe('127.0.0.1');
});

it('shows the change to the domain\'s manager with the address as actor, and filters by it', function () {
    ppwSignIn();
    ppwPut()->assertNoContent();
    $this->postJson('/api/v1/auth/logout')->assertNoContent();

    $manager = User::factory()->create(['name' => 'Max Manager']);
    DomainGrant::query()->forceCreate(['user_id' => $manager->id, 'domain_id' => 10, 'domain_name' => 'example.org', 'role' => 'manager']);
    $this->actingAs($manager, 'web');

    $this->getJson('/api/v1/audit-log')->assertOk()
        ->assertJsonPath('data.0.action', 'portal.password_changed')
        ->assertJsonPath('data.0.actor_kind', 'mailbox')
        ->assertJsonPath('data.0.actor_name', 'anna@example.org');
    expect($this->getJson('/api/v1/audit-log/actors')->assertOk()->json('data'))
        ->toContain(['key' => 'mailbox:anna@example.org', 'kind' => 'mailbox', 'name' => 'anna@example.org']);
    expect(collect($this->getJson('/api/v1/audit-log?actor=mailbox:anna@example.org')->assertOk()->json('data'))->pluck('action')->all())
        ->toBe(['portal.password_changed'])
        ->and($this->getJson('/api/v1/audit-log?actor=mailbox:bob@example.org')->assertOk()->json('data'))->toBe([]);
    $this->getJson('/api/v1/dashboard')->assertOk()->assertJsonPath('recent_activity.0.actor_kind', 'mailbox');
});

it('never puts either password in the audit row, the logs or a reported exception', function () {
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $context = $e->context;
        if (isset($context['exception']) && $context['exception'] instanceof Throwable) {
            // The rendered trace cuts string arguments at 15 characters, so read the app's frames whole
            // as well (the test client's own frames hold the request body it sends, which a real request
            // never puts on the stack).
            $arguments = [];
            for ($thrown = $context['exception']; $thrown !== null; $thrown = $thrown->getPrevious()) {
                foreach ($thrown->getTrace() as $frame) {
                    $class = $frame['class'] ?? '';
                    if (! str_starts_with($class, 'App\\') && ! str_contains($class, '@anonymous')) {
                        continue;
                    }
                    foreach ($frame['args'] ?? [] as $argument) {
                        $arguments[] = is_scalar($argument) ? (string) $argument : get_debug_type($argument);
                    }
                }
            }
            $context['exception'] = (string) $context['exception'].' '.implode(' ', $arguments);
        }
        $logged[] = $e->message.' '.var_export($context, true);
    });
    ppwSignIn();

    // A failure after the hash is written: the kick throws (MailboxSessions never does, but a bug could).
    app()->instance(MailboxSessions::class, new class extends MailboxSessions
    {
        public function __construct() {}

        public function kick(string ...$addresses): void
        {
            throw new RuntimeException('kick exploded');
        }
    });
    $failed = ppwPut()->assertStatus(500);

    // A failure inside the current-password check, with the password as an argument on the stack.
    app()->instance(PasswordChecker::class, new class implements PasswordChecker
    {
        public function verify(string $user, #[SensitiveParameter] string $password, ?string $remoteIp = null): bool
        {
            throw new RuntimeException('checker exploded');
        }
    });
    $failedCheck = ppwPut(['current_password' => PPW_NEW, 'password' => 'another new passphrase 8', 'password_confirmation' => 'another new passphrase 8'])->assertStatus(500);

    expect($logged)->not->toBeEmpty()
        ->and(implode("\n", $logged))->toContain('kick exploded')->toContain('checker exploded');
    foreach ([PPW_OLD, PPW_NEW, 'another new passphrase 8'] as $secret) {
        expect(implode("\n", $logged))->not->toContain($secret)
            ->and($failed->getContent().$failedCheck->getContent())->not->toContain($secret)
            ->and(json_encode(AuditEntry::query()->get()->toArray()))->not->toContain($secret)
            ->and(json_encode(session()->all()))->not->toContain($secret);
    }
});

// --- Refusals ------------------------------------------------------------------------------------

it('refuses a wrong current password with 422, changes nothing and logs it for fail2ban', function () {
    config(['session.driver' => 'database']);
    $current = ppwSession('anna@example.org');
    $other = ppwSession('anna@example.org');
    $before = ppwHash('anna@example.org');
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure kind=mailbox user=anna@example.org ip=127.0.0.1');

    ppwAs($current)->putJson('/api/v1/portal/password', ppwBody(['current_password' => 'not my password at all']))
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.current_password', ['The password is incorrect.'])
        ->assertJsonMissingPath('errors.password');

    expect(ppwHash('anna@example.org'))->toBe($before)
        ->and($this->kicked)->toBe([])
        ->and(AuditEntry::query()->count())->toBe(0)
        ->and(sessionRowIds())->toEqualCanonicalizing([$current, $other]);
    ppwAs($other)->getJson('/api/v1/portal/me')->assertOk();
});

it('refuses a new password the mailbox rules refuse, without asking Dovecot', function (array $over, string $message) {
    ppwSignIn();
    $before = ppwHash('anna@example.org');
    ppwChecker()->calls = [];

    ppwPut($over)->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.password', [$message])
        ->assertJsonMissingPath('errors.current_password');

    expect(ppwHash('anna@example.org'))->toBe($before)
        ->and(ppwChecker()->calls)->toBe([])
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'too short' => [['password' => 'Short pass1', 'password_confirmation' => 'Short pass1'], 'Too weak — use 12+ characters with a number or symbol.'],
    'long but weak' => [['password' => 'abcdefghijklmnop', 'password_confirmation' => 'abcdefghijklmnop'], 'Too weak — use 12+ characters with a number or symbol.'],
    'over 72 bytes' => [['password' => str_repeat('ä', 37).'1', 'password_confirmation' => str_repeat('ä', 37).'1'], 'Use at most 72 bytes.'],
    'confirmation differs' => [['password_confirmation' => 'something else entirely 9'], 'The password field confirmation does not match.'],
    'a NUL byte' => [['password' => "a brand new\0passphrase 7", 'password_confirmation' => "a brand new\0passphrase 7"], 'Remove the NUL character from the password.'],
]);

it('refuses a missing or non-string field', function (array $over, string $field) {
    ppwSignIn();

    ppwPut($over)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => [$field]]);
    expect(AuditEntry::query()->count())->toBe(0);
})->with([
    'no current password' => [['current_password' => null], 'current_password'],
    'current password not a string' => [['current_password' => ['x']], 'current_password'],
    'no new password' => [['password' => null], 'password'],
    'new password not a string' => [['password' => 12345678901234, 'password_confirmation' => 12345678901234], 'password'],
]);

it('checks the new password against Have I Been Pwned when that is on', function () {
    config(['emailsquire.hibp' => true]);
    // The k-anonymity range answer for PPW_NEW's SHA-1 prefix, listing its own suffix as breached.
    $sha1 = strtoupper(sha1(PPW_NEW));
    Http::fake(['api.pwnedpasswords.com/range/*' => Http::response(substr($sha1, 5).":42\r\n")]);
    ppwSignIn();

    ppwPut()->assertStatus(422)->assertJsonStructure(['errors' => ['password']]);
    expect(AuditEntry::query()->count())->toBe(0);
});

it('says so in Finnish', function () {
    ppwSignIn();

    ppwPut(['current_password' => 'not my password at all'])->assertStatus(422);
    $this->withHeader('Accept-Language', 'fi');

    $this->putJson('/api/v1/portal/password', ppwBody(['current_password' => 'not my password at all']))->assertStatus(422)
        ->assertJsonPath('errors.current_password', ['Salasana on väärä.']);
    $this->putJson('/api/v1/portal/password', ppwBody(['password' => 'Short pass1', 'password_confirmation' => 'Short pass1']))->assertStatus(422)
        ->assertJsonPath('errors.password', ['Liian heikko – käytä vähintään 12 merkkiä sekä numero tai erikoismerkki.']);
    $this->putJson('/api/v1/portal/password', ppwBody(['password' => str_repeat('ä', 37).'1', 'password_confirmation' => str_repeat('ä', 37).'1']))->assertStatus(422)
        ->assertJsonPath('errors.password', ['Käytä enintään 72 tavua.']);
    $this->putJson('/api/v1/portal/password', ppwBody(['password' => "a brand new\0passphrase 7", 'password_confirmation' => "a brand new\0passphrase 7"]))->assertStatus(422)
        ->assertJsonPath('errors.password', ['Poista salasanasta NUL-merkki.']);
    ppwChecker()->failWith(new DovecotAuthException(DovecotAuthFailure::Unreachable, 'socket missing'));
    $this->putJson('/api/v1/portal/password', ppwBody())->assertStatus(503)
        ->assertJsonPath('detail', 'Nykyistä salasanaasi ei voi juuri nyt tarkistaa. Yritä hetken kuluttua uudelleen.');
});

it('answers 503 when Dovecot cannot check the current password, and changes nothing', function () {
    ppwSignIn();
    $before = ppwHash('anna@example.org');
    ppwChecker()->failWith(new DovecotAuthException(DovecotAuthFailure::Unreachable, 'socket missing'));
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
        $logged[] = $e->message.' '.json_encode($e->context);
    });

    ppwPut()->assertStatus(503)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'password_check_unavailable')
        ->assertJsonPath('detail', 'Your current password can\'t be checked right now. Try again in a moment.');

    expect(ppwHash('anna@example.org'))->toBe($before)
        ->and(AuditEntry::query()->count())->toBe(0)
        ->and($this->kicked)->toBe([])
        ->and(implode("\n", $logged))->toContain('Unreachable')->not->toContain('anna@example.org');
});

it('changes nothing when the mailbox is disabled while the current password is being checked', function () {
    ppwSignIn();
    $before = ppwHash('anna@example.org');
    // Dovecot says yes, and meanwhile an admin disables the mailbox.
    app()->instance(PasswordChecker::class, new class implements PasswordChecker
    {
        public function verify(string $user, #[SensitiveParameter] string $password, ?string $remoteIp = null): bool
        {
            DisabledMailbox::query()->forceCreate([
                'mailbox_email' => $user, 'domain_id' => 10, 'saved_password' => '{BLF-CRYPT}saved', 'disabled_at' => now(),
            ]);

            return true;
        }
    });

    ppwPut()->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

    expect(ppwHash('anna@example.org'))->toBe($before)
        ->and(DisabledMailbox::query()->findOrFail('anna@example.org')->saved_password)->toBe('{BLF-CRYPT}saved')
        ->and(AuditEntry::query()->count())->toBe(0)
        ->and($this->kicked)->toBe([]);
});

it('refuses the change when an admin resets the password while the current one is being checked', function () {
    config(['session.driver' => 'database']);
    $current = ppwSession('anna@example.org');
    $other = ppwSession('anna@example.org');
    $reset = null;
    // Dovecot says yes to the old password, and meanwhile an admin resets it in the console.
    app()->instance(PasswordChecker::class, new class($reset) implements PasswordChecker
    {
        public function __construct(private ?string &$reset) {}

        public function verify(string $user, #[SensitiveParameter] string $password, ?string $remoteIp = null): bool
        {
            $this->reset = app(MailboxRepository::class)->resetPassword(Mailbox::query()->where('email', $user)->firstOrFail());

            return true;
        }
    });
    $this->kicked = [];

    ppwAs($current)->putJson('/api/v1/portal/password', ppwBody())->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

    // The admin's password stands; this request wrote nothing, audited nothing and kicked nobody (the
    // one kick is the reset's own).
    $stored = substr(ppwHash('anna@example.org'), strlen('{BLF-CRYPT}'));
    expect($reset)->toBeString()
        ->and(password_verify((string) $reset, $stored))->toBeTrue()
        ->and(password_verify(PPW_NEW, $stored))->toBeFalse()
        ->and(AuditEntry::query()->pluck('action')->all())->toBe(['mailbox.password_reset'])
        ->and($this->kicked)->toBe([['anna@example.org']]);
    // Both portal sessions end at their next request: the stored hash moved under them.
    ppwAs($current)->getJson('/api/v1/portal/me')->assertUnauthorized();
    ppwAs($other)->getJson('/api/v1/portal/me')->assertUnauthorized();
});

// --- Limits and guards ---------------------------------------------------------------------------

it('allows 6 attempts a minute per mailbox: the seventh is 429, and another mailbox is unaffected', function () {
    $this->freezeTime();
    ppwSignIn();

    foreach (range(1, 6) as $i) {
        ppwPut(['current_password' => "guess number {$i}"])->assertStatus(422);
    }
    ppwPut()->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'too_many_requests');
    expect(ppwChecker()->users())->toHaveCount(1 + 6) // the sign-in, then the six guesses; never the seventh
        ->and(password_verify(PPW_OLD, substr(ppwHash('anna@example.org'), strlen('{BLF-CRYPT}'))))->toBeTrue();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    ppwSignIn('bob@example.org');
    ppwPut()->assertNoContent();

    // A minute later the mailbox may try again.
    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->travel(61)->seconds();
    ppwSignIn();
    ppwPut()->assertNoContent();
});

it('needs a portal session: signed out or a console session gets 401', function () {
    $this->putJson('/api/v1/portal/password', ppwBody())->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

    $this->actingAs(User::factory()->create(['email' => 'anna@example.org']), 'web');
    $this->putJson('/api/v1/portal/password', ppwBody())->assertUnauthorized();
    expect(ppwChecker()->calls)->toBe([]);
});
