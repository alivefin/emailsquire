<?php

use App\Auth\Invitations;
use App\Mail\SecurityNotice;
use App\MailServer\AuditLog;
use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\Invitation;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\GuideFixture;

// The Users screen's API (plan 2c, Task 5): server admins list, change and delete console users and
// their grants. users.org (id 80) has the manager Mia and the viewer Vic; other.org (id 81) has Vic as
// a viewer too; bücher.example (id 82) is an IDN domain nobody has.

beforeEach(function () {
    config(['emailsquire.require_two_factor' => 'off', 'emailsquire.managers_can_share' => false]);
    Mail::fake();
    GuideFixture::load('trixie');
    DB::table(GuideSchema::table('virtual_domains'))->insert([
        ['id' => 80, 'name' => 'users.org'], ['id' => 81, 'name' => 'other.org'], ['id' => 82, 'name' => 'xn--bcher-kva.example'],
    ]);

    $this->admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@console.test', 'is_server_admin' => true, 'password_set_at' => now()]);
    $this->manager = User::factory()->create(['name' => 'Mia Manager', 'email' => 'mia@console.test', 'password_set_at' => now(), 'locale' => 'fi']);
    $this->viewer = User::factory()->create(['name' => 'Vic Viewer', 'email' => 'vic@console.test', 'password_set_at' => now()]);
    usGrant($this->manager, 80, 'users.org', 'manager', $this->admin);
    usGrant($this->viewer, 80, 'users.org', 'viewer', $this->admin);
    usGrant($this->viewer, 81, 'other.org', 'viewer', $this->admin);
});

function usGrant(User $user, int $domainId, string $domainName, string $role, ?User $by = null): DomainGrant
{
    return DomainGrant::query()->forceCreate([
        'user_id' => $user->id, 'domain_id' => $domainId, 'domain_name' => $domainName, 'role' => $role, 'granted_by' => $by?->id,
    ]);
}

/** Signs in as $as (the admin by default), step-up confirmed unless $stepUp is false. */
function usAs(?User $as = null, bool $stepUp = true)
{
    test()->actingAs($as ?? test()->admin);
    if ($stepUp) {
        confirmStepUp();
    }

    return test();
}

function usAdmin(string $email, string $name = 'Ben Admin'): User
{
    return User::factory()->create(['name' => $name, 'email' => $email, 'is_server_admin' => true, 'password_set_at' => now()]);
}

/** @return list<string> the audit actions written, oldest first */
function usActions(): array
{
    return AuditEntry::query()->orderBy('id')->pluck('action')->all();
}

/** Two `sessions` rows for $user and one for someone else; answers the rows left of $user. */
function usSessions(User $user): void
{
    insertSessionRow($user->id, "s-{$user->id}-1");
    insertSessionRow($user->id, "s-{$user->id}-2");
    insertSessionRow(test()->admin->id, 'admin-session');
}

function usSessionCount(User $user): int
{
    return DB::table('sessions')->where('user_id', $user->id)->count();
}

// --- Authorization -------------------------------------------------------------------------------

it('answers 404 to anyone but a server admin on every users route', function (string $method, string $uri, array $body) {
    $uri = str_replace('{user}', (string) $this->viewer->id, $uri);
    foreach ([$this->manager, $this->viewer] as $as) {
        usAs($as)->json($method, "/api/v1/{$uri}", $body)->assertNotFound()->assertJsonPath('code', 'not_found');
    }
    expect(AuditEntry::query()->count())->toBe(0);
})->with([
    'list' => ['GET', 'users', []],
    'show' => ['GET', 'users/{user}', []],
    'patch name' => ['PATCH', 'users/{user}', ['name' => 'X']],
    'patch status' => ['PATCH', 'users/{user}', ['status' => 'deactivated']],
    'delete' => ['DELETE', 'users/{user}', []],
    'add grant' => ['POST', 'users/{user}/grants', ['domain' => 'users.org', 'role' => 'manager']],
    'remove grant' => ['DELETE', 'users/{user}/grants/users.org', []],
    'reset two-factor' => ['POST', 'users/{user}/two-factor/reset', []],
]);

// --- List ----------------------------------------------------------------------------------------

it('lists every console user by name with their counts, two-factor and last sign-in', function () {
    $this->manager->forceFill(['last_login_at' => '2026-10-01 12:00:00'])->save();
    tfaEnrol($this->admin);
    // A grant under a name the guide row no longer carries grants nothing and counts nothing.
    usGrant($this->manager, 82, 'old-name.org', 'manager');
    // A grant left on a server admin is not counted: they have every domain.
    usGrant($this->admin, 81, 'other.org', 'viewer');

    $response = usAs(stepUp: false)->getJson('/api/v1/users')->assertOk();

    expect($response->json('data.*.email'))->toBe(['ada@console.test', 'mia@console.test', 'vic@console.test'])
        ->and($response->json('meta'))->toBe(['page' => 1, 'per_page' => 10, 'total' => 3, 'last_page' => 1]);
    expect($response->json('data.0'))->toBe([
        'id' => $this->admin->id, 'name' => 'Ada Admin', 'email' => 'ada@console.test', 'is_server_admin' => true,
        'status' => 'active', 'two_factor_confirmed' => true, 'manager_of' => 0, 'viewer_of' => 0, 'last_login_at' => null,
    ]);
    expect($response->json('data.1'))->toMatchArray([
        'is_server_admin' => false, 'two_factor_confirmed' => false, 'manager_of' => 1, 'viewer_of' => 0,
        'last_login_at' => '2026-10-01T12:00:00+00:00',
    ])->and($response->json('data.2'))->toMatchArray(['manager_of' => 0, 'viewer_of' => 2]);
});

it('searches names and emails and pages the list', function () {
    User::factory()->create(['name' => '', 'email' => 'zed@elsewhere.test']);
    User::factory()->create(['name' => 'Bea 50%_off', 'email' => 'bea@elsewhere.test']);

    usAs(stepUp: false);
    expect($this->getJson('/api/v1/users?q=VIEWER')->json('data.*.email'))->toBe(['vic@console.test'])
        ->and($this->getJson('/api/v1/users?q=elsewhere')->json('data.*.email'))->toBe(['bea@elsewhere.test', 'zed@elsewhere.test'])
        // LIKE wildcards in the search are literal.
        ->and($this->getJson('/api/v1/users?q=%25_')->json('data.*.email'))->toBe(['bea@elsewhere.test']);

    $page = $this->getJson('/api/v1/users?page=2&per_page=2')->assertOk();
    // An empty name sorts by the email: Ada, Bea, Mia, Vic, zed@.
    expect($page->json('data.*.email'))->toBe(['mia@console.test', 'vic@console.test'])
        ->and($page->json('meta'))->toBe(['page' => 2, 'per_page' => 2, 'total' => 5, 'last_page' => 3]);
    expect($this->getJson('/api/v1/users?per_page=500')->json('meta.per_page'))->toBe(200);
});

it('lists the users without a query per user', function () {
    foreach (range(1, 6) as $i) {
        usGrant(User::factory()->create(), 80, 'users.org', 'viewer');
    }
    usAs(stepUp: false);
    $count = 0;
    Event::listen(QueryExecuted::class, function () use (&$count) {
        $count++;
    });

    $this->getJson('/api/v1/users?per_page=50')->assertOk()->assertJsonCount(9, 'data');

    expect($count)->toBeLessThanOrEqual(6);
});

// --- Show ----------------------------------------------------------------------------------------

it('shows a user with their live grants and pending invitations', function () {
    $gone = User::factory()->create(['email' => 'gone@console.test']);
    $nameless = User::factory()->create(['name' => '', 'email' => 'nameless@console.test']);
    usGrant($this->viewer, 82, 'xn--bcher-kva.example', 'manager', $nameless);
    DomainGrant::query()->where('user_id', $this->viewer->id)->where('domain_id', 81)->update(['granted_by' => $gone->id]);
    $gone->delete();
    $pending = Invitation::query()->forceCreate([
        'token_hash' => hash('sha256', 'a'), 'email' => 'vic@console.test', 'domain_id' => null, 'domain_name' => null,
        'role' => 'server_admin', 'invited_by' => $this->admin->id, 'expires_at' => now()->addHours(72), 'locale' => 'en',
    ]);
    Invitation::query()->forceCreate([
        'token_hash' => hash('sha256', 'b'), 'email' => 'vic@console.test', 'domain_id' => 80, 'domain_name' => 'users.org',
        'role' => 'manager', 'invited_by' => $this->admin->id, 'expires_at' => now()->addHours(72), 'locale' => 'en', 'accepted_at' => now(),
    ]);

    $response = usAs(stepUp: false)->getJson("/api/v1/users/{$this->viewer->id}")->assertOk();

    expect($response->json())->toMatchArray([
        'id' => $this->viewer->id, 'email' => 'vic@console.test', 'manager_of' => 1, 'viewer_of' => 2,
    ]);
    expect(collect($response->json('grants'))->map(fn ($g) => [$g['domain'], $g['domain_unicode'], $g['role'], $g['granted_by_name']])->all())->toBe([
        ['other.org', 'other.org', 'viewer', null],
        ['users.org', 'users.org', 'viewer', 'Ada Admin'],
        ['xn--bcher-kva.example', 'bücher.example', 'manager', 'nameless@console.test'],
    ]);
    expect($response->json('grants.0.granted_at'))->toBeString();
    expect($response->json('invitations'))->toHaveCount(1)
        ->and($response->json('invitations.0'))->toMatchArray([
            'id' => $pending->id, 'role' => 'server_admin', 'domain' => null, 'domain_unicode' => null,
            'invited_by_name' => 'Ada Admin', 'state' => 'valid',
        ]);
});

it('answers 404 for a user id nobody has', function () {
    usAs(stepUp: false)->getJson('/api/v1/users/999999')->assertNotFound()->assertJsonPath('code', 'not_found');
});

// --- PATCH ---------------------------------------------------------------------------------------

it('changes a name without step-up and audits nothing', function () {
    usAs(stepUp: false)->patchJson("/api/v1/users/{$this->viewer->id}", ['name' => 'Victor'])
        ->assertOk()->assertJsonPath('name', 'Victor')->assertJsonPath('viewer_of', 2);

    expect($this->viewer->refresh()->name)->toBe('Victor')
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('asks for step-up before changing an email, role or status', function (array $body) {
    usSessions($this->viewer);

    usAs(stepUp: false)->patchJson("/api/v1/users/{$this->viewer->id}", $body)
        ->assertStatus(423)->assertHeader('Content-Type', 'application/problem+json')->assertJsonPath('code', 'step_up_required');

    $fresh = $this->viewer->refresh();
    expect($fresh->email)->toBe('vic@console.test')
        ->and($fresh->is_server_admin)->toBeFalse()
        ->and($fresh->status)->toBe('active')
        ->and(usSessionCount($this->viewer))->toBe(2)
        ->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
})->with([
    'email' => [['email' => 'victor@console.test']],
    'role' => [['is_server_admin' => true]],
    'status' => [['status' => 'deactivated']],
    'name with status' => [['name' => 'Victor', 'status' => 'deactivated']],
]);

it('refuses an empty change', function () {
    usAs()->patchJson("/api/v1/users/{$this->viewer->id}", [])->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});

it('changes an email, lowercased, audits it and tells the old address in the user\'s language', function () {
    $invitation = Invitation::query()->forceCreate([
        'token_hash' => hash('sha256', 'm'), 'email' => 'mia@console.test', 'domain_id' => 81, 'domain_name' => 'other.org',
        'role' => 'viewer', 'invited_by' => $this->admin->id, 'expires_at' => now()->addHours(72), 'locale' => 'en',
    ]);

    usAs()->patchJson("/api/v1/users/{$this->manager->id}", ['email' => ' Mia.New@Console.TEST '])
        ->assertOk()->assertJsonPath('email', 'mia.new@console.test');

    expect($this->manager->refresh()->email)->toBe('mia.new@console.test');
    $audit = AuditEntry::query()->where('action', 'user.email_changed')->sole();
    expect($audit->only(['action', 'actor_id', 'object_type', 'object_key', 'domain_name', 'changes']))->toBe([
        'action' => 'user.email_changed', 'actor_id' => (string) $this->admin->id, 'object_type' => 'user',
        'object_key' => 'mia.new@console.test', 'domain_name' => null,
        'changes' => ['email' => ['mia@console.test', 'mia.new@console.test']],
    ]);
    Mail::assertQueued(SecurityNotice::class, 1);
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'email_changed'
        && $mail->hasTo('mia@console.test') && ! $mail->hasTo('mia.new@console.test') && $mail->locale === 'fi');
    // A pending invitation to the old address would create a new account for it: it is revoked.
    expect($invitation->refresh()->email)->toBe('mia@console.test')
        ->and(app(Invitations::class)->state($invitation))->toBe('revoked');
});

it('revokes every pending invitation to the old address when the email changes, a server-admin one too', function () {
    [$server, $domain, $accepted, $other] = usInvitations('mia@console.test');

    usAs()->patchJson("/api/v1/users/{$this->manager->id}", ['email' => 'mia.new@console.test'])->assertOk();

    usAssertRevoked($server, $domain, $accepted, $other);
});

it('revokes every pending invitation to a deleted user\'s email, a server-admin one too', function () {
    [$server, $domain, $accepted, $other] = usInvitations('mia@console.test');

    usAs()->deleteJson("/api/v1/users/{$this->manager->id}")->assertNoContent();

    usAssertRevoked($server, $domain, $accepted, $other);
    expect(usActions())->toBe(['user.deleted', 'invitation.revoked', 'invitation.revoked']);
});

it('a revoked invitation to a deleted user\'s email no longer creates an account', function () {
    [$server] = usInvitations('mia@console.test', 'the-token-of-the-server-invitation-00000000');
    usAs()->deleteJson("/api/v1/users/{$this->manager->id}")->assertNoContent();
    auth()->guard('web')->logout();
    $this->flushSession();

    $this->postJson('/api/v1/auth/invitations/accept', [
        'token' => 'the-token-of-the-server-invitation-00000000',
        'name' => 'Not Mia', 'password' => 'a long new passphrase 1', 'password_confirmation' => 'a long new passphrase 1',
    ])->assertStatus(410)->assertJsonPath('state', 'revoked');

    expect(User::query()->where('email', 'mia@console.test')->exists())->toBeFalse()
        ->and($server->refresh()->accepted_at)->toBeNull();
});

/**
 * Invitations to $email: a pending server-admin one ($token's), a pending domain one and an accepted
 * one; and a pending one to someone else.
 *
 * @return array{0: Invitation, 1: Invitation, 2: Invitation, 3: Invitation}
 */
function usInvitations(string $email, string $token = 'server'): array
{
    $make = fn (string $to, ?int $domainId, ?string $domain, string $role, string $tok, array $extra = []) => Invitation::query()->forceCreate([
        'token_hash' => hash('sha256', $tok), 'email' => $to, 'domain_id' => $domainId, 'domain_name' => $domain,
        'role' => $role, 'invited_by' => test()->admin->id, 'expires_at' => now()->addHours(72), 'locale' => 'en', ...$extra,
    ]);

    return [
        $make($email, null, null, 'server_admin', $token),
        $make($email, 81, 'other.org', 'viewer', 'domain'),
        $make($email, 80, 'users.org', 'manager', 'accepted', ['accepted_at' => now()]),
        $make('someone@example.net', 81, 'other.org', 'viewer', 'other'),
    ];
}

function usAssertRevoked(Invitation $server, Invitation $domain, Invitation $accepted, Invitation $other): void
{
    expect($server->refresh()->revoked_at)->not->toBeNull()
        ->and($domain->refresh()->revoked_at)->not->toBeNull()
        ->and($accepted->refresh()->revoked_at)->toBeNull()
        ->and($other->refresh()->revoked_at)->toBeNull();
    expect(AuditEntry::query()->where('action', 'invitation.revoked')->orderBy('id')->get()->map->only(['actor_id', 'object_type', 'object_key', 'domain_name', 'changes'])->all())->toBe([
        ['actor_id' => (string) test()->admin->id, 'object_type' => 'invitation', 'object_key' => 'mia@console.test', 'domain_name' => null, 'changes' => ['role' => 'server_admin']],
        ['actor_id' => (string) test()->admin->id, 'object_type' => 'invitation', 'object_key' => 'mia@console.test', 'domain_name' => 'other.org', 'changes' => ['role' => 'viewer']],
    ]);
}

it('refuses an email another user has, in any case', function () {
    usAs()->patchJson("/api/v1/users/{$this->manager->id}", ['email' => 'VIC@console.test'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonValidationErrors('email');
    usAs()->patchJson("/api/v1/users/{$this->manager->id}", ['email' => 'not-an-email'])->assertStatus(422);

    expect($this->manager->refresh()->email)->toBe('mia@console.test')
        ->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('keeps the same email without an audit row or notice', function () {
    usAs()->patchJson("/api/v1/users/{$this->manager->id}", ['email' => 'mia@console.test'])->assertOk();

    expect(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('drops the old address\'s reset and setup tokens when the email changes', function () {
    foreach (['password_reset_tokens', 'admin_setup_tokens'] as $table) {
        DB::table($table)->insert(['email' => 'mia@console.test', 'token' => 'x', 'created_at' => now()]);
    }

    usAs()->patchJson("/api/v1/users/{$this->manager->id}", ['email' => 'mia.new@console.test'])->assertOk();

    expect(DB::table('password_reset_tokens')->count())->toBe(0)
        ->and(DB::table('admin_setup_tokens')->count())->toBe(0);
});

it('makes a user a server admin, ends their sessions and replaces their grants', function () {
    usSessions($this->viewer);
    PinnedDomain::query()->forceCreate(['user_id' => $this->viewer->id, 'domain_id' => 80, 'domain_name' => 'users.org', 'position' => 1]);

    usAs()->patchJson("/api/v1/users/{$this->viewer->id}", ['is_server_admin' => true])
        ->assertOk()->assertJsonPath('is_server_admin', true)->assertJsonPath('viewer_of', 0);

    expect($this->viewer->refresh()->is_server_admin)->toBeTrue()
        ->and(DomainGrant::query()->where('user_id', $this->viewer->id)->count())->toBe(0)
        ->and(usSessionCount($this->viewer))->toBe(0)
        ->and(DB::table('sessions')->where('id', 'admin-session')->exists())->toBeTrue()
        // A server admin still sees the pinned domain.
        ->and(PinnedDomain::query()->where('user_id', $this->viewer->id)->count())->toBe(1);
    expect(usActions())->toBe(['grant.removed', 'grant.removed', 'user.role_changed']);
    expect(AuditEntry::query()->where('action', 'grant.removed')->orderBy('domain_name')->get()->map->only(['object_key', 'domain_name', 'changes'])->all())->toBe([
        ['object_key' => 'vic@console.test', 'domain_name' => 'other.org', 'changes' => ['role' => 'viewer']],
        ['object_key' => 'vic@console.test', 'domain_name' => 'users.org', 'changes' => ['role' => 'viewer']],
    ]);
    expect(AuditEntry::query()->where('action', 'user.role_changed')->sole()->only(['actor_id', 'object_type', 'object_key', 'changes']))->toBe([
        'actor_id' => (string) $this->admin->id, 'object_type' => 'user', 'object_key' => 'vic@console.test',
        'changes' => ['is_server_admin' => [false, true], 'grants_removed' => 2],
    ]);
});

it('takes the server-admin role away, ends their sessions and drops pins they can no longer see', function () {
    $ben = usAdmin('ben@console.test');
    usSessions($ben);
    PinnedDomain::query()->forceCreate(['user_id' => $ben->id, 'domain_id' => 80, 'domain_name' => 'users.org', 'position' => 1]);

    usAs()->patchJson("/api/v1/users/{$ben->id}", ['is_server_admin' => false])->assertOk()->assertJsonPath('is_server_admin', false);

    expect($ben->refresh()->is_server_admin)->toBeFalse()
        ->and(usSessionCount($ben))->toBe(0)
        ->and(PinnedDomain::query()->where('user_id', $ben->id)->count())->toBe(0);
    expect(AuditEntry::query()->sole()->only(['action', 'object_key', 'changes']))->toBe([
        'action' => 'user.role_changed', 'object_key' => 'ben@console.test', 'changes' => ['is_server_admin' => [true, false]],
    ]);
});

it('deactivates a user, ending their sessions, and reactivates them', function () {
    usSessions($this->viewer);

    usAs()->patchJson("/api/v1/users/{$this->viewer->id}", ['status' => 'deactivated'])->assertOk()->assertJsonPath('status', 'deactivated');

    expect($this->viewer->refresh()->status)->toBe('deactivated')
        ->and(usSessionCount($this->viewer))->toBe(0)
        ->and(DB::table('sessions')->where('id', 'admin-session')->exists())->toBeTrue()
        // The grants stay; a deactivated user has no role until reactivated.
        ->and(DomainGrant::query()->where('user_id', $this->viewer->id)->count())->toBe(2);

    insertSessionRow($this->viewer->id, 'later');
    usAs()->patchJson("/api/v1/users/{$this->viewer->id}", ['status' => 'active'])->assertOk()->assertJsonPath('status', 'active');

    expect($this->viewer->refresh()->status)->toBe('active')
        ->and(usSessionCount($this->viewer))->toBe(1);
    expect(AuditEntry::query()->orderBy('id')->get()->map->only(['action', 'object_key', 'changes'])->all())->toBe([
        ['action' => 'user.deactivated', 'object_key' => 'vic@console.test', 'changes' => null],
        ['action' => 'user.reactivated', 'object_key' => 'vic@console.test', 'changes' => null],
    ]);
});

it('signs a deactivated user\'s open session out on its next request', function () {
    config(['session.driver' => 'database']);
    $this->viewer->forceFill(['password' => 'correct horse battery'])->save();
    $session = usCookie('')->postJson('/api/v1/auth/login', ['email' => 'vic@console.test', 'password' => 'correct horse battery'])
        ->assertOk()->getCookie(config('session.cookie'))->getValue();
    usCookie($session)->getJson('/api/v1/me')->assertOk();

    // The admin, in a browser of their own, confirms it's them and deactivates the viewer.
    $admin = usCookie('')->postJson('/api/v1/auth/login', ['email' => 'ada@console.test', 'password' => 'password'])
        ->assertOk()->getCookie(config('session.cookie'))->getValue();
    usCookie($admin)->postJson('/api/v1/auth/confirm-password', ['password' => 'password'])->assertSuccessful();
    usCookie($admin)->patchJson("/api/v1/users/{$this->viewer->id}", ['status' => 'deactivated'])->assertOk();

    usCookie($session)->getJson('/api/v1/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
    usCookie($session)->getJson('/api/v1/domains')->assertUnauthorized();
});

/** Only the session cookie says who the request is (see cpAs() in ChangePasswordTest). */
function usCookie(string $sessionId)
{
    app('auth')->forgetGuards();
    session()->flush();

    return test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

it('changes no audit row or session when the role and status are what they were', function () {
    usSessions($this->viewer);

    usAs()->patchJson("/api/v1/users/{$this->viewer->id}", ['is_server_admin' => false, 'status' => 'active'])->assertOk();

    expect(AuditEntry::query()->count())->toBe(0)
        ->and(usSessionCount($this->viewer))->toBe(2);
});

// --- Guards --------------------------------------------------------------------------------------

it('refuses to demote, deactivate, delete or reset the two-factor of yourself', function (string $method, string $uri, array $body) {
    usAdmin('ben@console.test');
    tfaEnrol($this->admin);

    usAs()->json($method, str_replace('{me}', (string) $this->admin->id, $uri), $body)
        ->assertStatus(409)->assertJsonPath('code', 'cannot_change_self');

    $fresh = $this->admin->refresh();
    expect($fresh->is_server_admin)->toBeTrue()
        ->and($fresh->status)->toBe('active')
        ->and($fresh->two_factor_confirmed_at)->not->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'demote' => ['PATCH', '/api/v1/users/{me}', ['is_server_admin' => false]],
    'deactivate' => ['PATCH', '/api/v1/users/{me}', ['status' => 'deactivated']],
    'delete' => ['DELETE', '/api/v1/users/{me}', []],
    'reset two-factor' => ['POST', '/api/v1/users/{me}/two-factor/reset', []],
]);

it('lets you change your own name and email', function () {
    usAs()->patchJson("/api/v1/users/{$this->admin->id}", ['name' => 'Ada L.', 'email' => 'ada.l@console.test'])->assertOk();

    expect($this->admin->refresh()->only(['name', 'email']))->toBe(['name' => 'Ada L.', 'email' => 'ada.l@console.test']);
});

it('keeps the last active server admin: A demotes B, then B\'s request to demote A is refused', function () {
    $ben = usAdmin('ben@console.test');

    usAs()->patchJson("/api/v1/users/{$ben->id}", ['is_server_admin' => false])->assertOk();
    // Ben's request was authorised before Ada's change committed: his model still says server admin.
    app('auth')->forgetGuards();
    usAs($ben)->patchJson("/api/v1/users/{$this->admin->id}", ['is_server_admin' => false])
        ->assertStatus(409)->assertJsonPath('code', 'last_server_admin');

    expect($this->admin->refresh()->is_server_admin)->toBeTrue()
        ->and(usActions())->toBe(['user.role_changed']);
});

it('refuses to deactivate, demote or delete the last active server admin', function (string $method, array $body) {
    $ben = usAdmin('ben@console.test');
    // Ben was deactivated a moment ago; his request was authorised before that committed.
    DB::table('users')->where('id', $ben->id)->update(['status' => 'deactivated']);

    usAs($ben)->json($method, "/api/v1/users/{$this->admin->id}", $body)
        ->assertStatus(409)->assertJsonPath('code', 'last_server_admin');

    expect($this->admin->refresh()->only(['is_server_admin', 'status']))->toBe(['is_server_admin' => true, 'status' => 'active'])
        ->and(AuditEntry::query()->count())->toBe(0);
})->with([
    'deactivate' => ['PATCH', ['status' => 'deactivated']],
    'demote' => ['PATCH', ['is_server_admin' => false]],
    'delete' => ['DELETE', []],
]);

it('lets an admin deactivate or demote a deactivated admin: that one was not counted', function () {
    $ben = usAdmin('ben@console.test');
    DB::table('users')->where('id', $ben->id)->update(['status' => 'deactivated']);

    usAs()->patchJson("/api/v1/users/{$ben->id}", ['is_server_admin' => false])->assertOk();
});

it('a server admin no longer active changes nobody, even with a request authorised earlier', function () {
    $ben = usAdmin('ben@console.test');
    $cy = usAdmin('cy@console.test', 'Cy Admin');
    DB::table('users')->where('id', $ben->id)->update(['is_server_admin' => false]);

    usAs($ben)->patchJson("/api/v1/users/{$cy->id}", ['is_server_admin' => false])->assertNotFound();

    expect($cy->refresh()->is_server_admin)->toBeTrue();
});

it('counts the active server admins with the users rows locked FOR UPDATE before changing anyone', function (string $method, array $body) {
    $ben = usAdmin('ben@console.test');
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    usAs()->json($method, "/api/v1/users/{$ben->id}", $body)->assertSuccessful();

    $lock = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from `users`') && str_contains($sql, '`is_server_admin`') && str_ends_with($sql, 'for update'));
    $write = collect($queries)->search(fn (string $sql) => str_starts_with($sql, 'update `users`') || str_starts_with($sql, 'delete from `users`'));
    expect($lock)->not->toBeFalse()->and($write)->not->toBeFalse()->and($lock)->toBeLessThan($write);
})->with([
    'demote' => ['PATCH', ['is_server_admin' => false]],
    'deactivate' => ['PATCH', ['status' => 'deactivated']],
    'delete' => ['DELETE', []],
]);

// --- DELETE --------------------------------------------------------------------------------------

it('deletes a user with their grants, pins and sessions, and revokes the invitations they sent', function () {
    $ben = usAdmin('ben@console.test');
    usGrant($this->manager, 81, 'other.org', 'viewer', $ben);
    usSessions($ben);
    PinnedDomain::query()->forceCreate(['user_id' => $ben->id, 'domain_id' => 80, 'domain_name' => 'users.org', 'position' => 1]);
    $sent = Invitation::query()->forceCreate([
        'token_hash' => hash('sha256', 's'), 'email' => 'new@example.net', 'domain_id' => 80, 'domain_name' => 'users.org',
        'role' => 'viewer', 'invited_by' => $ben->id, 'expires_at' => now()->addHours(72), 'locale' => 'en',
    ]);
    // Something Ben did earlier: his audit history stays.
    DB::transaction(fn () => app(AuditLog::class)->record('grant.created', 'user', 'mia@console.test', 'other.org', ['role' => 'viewer'], $ben));

    usAs()->deleteJson("/api/v1/users/{$ben->id}")->assertNoContent();

    expect(User::query()->whereKey($ben->id)->exists())->toBeFalse()
        ->and(usSessionCount($ben))->toBe(0)
        ->and(PinnedDomain::query()->where('user_id', $ben->id)->exists())->toBeFalse()
        // The grant Ben gave stays, with nobody named as its granter.
        ->and(DomainGrant::query()->where('user_id', $this->manager->id)->where('domain_id', 81)->value('granted_by'))->toBeNull()
        ->and(app(Invitations::class)->state($sent->refresh()))->toBe('revoked')
        ->and(AuditEntry::query()->where('actor_id', (string) $ben->id)->count())->toBe(1);
    expect(AuditEntry::query()->where('action', 'user.deleted')->sole()->only(['actor_id', 'object_type', 'object_key', 'domain_name']))->toBe([
        'actor_id' => (string) $this->admin->id, 'object_type' => 'user', 'object_key' => 'ben@console.test', 'domain_name' => null,
    ]);
});

it('deletes a user\'s grants with them', function () {
    usAs()->deleteJson("/api/v1/users/{$this->viewer->id}")->assertNoContent();

    expect(DomainGrant::query()->where('user_id', $this->viewer->id)->exists())->toBeFalse();
});

it('asks for step-up before deleting a user', function () {
    usAs(stepUp: false)->deleteJson("/api/v1/users/{$this->viewer->id}")->assertStatus(423)->assertJsonPath('code', 'step_up_required');

    expect(User::query()->whereKey($this->viewer->id)->exists())->toBeTrue();
});

// --- Grants --------------------------------------------------------------------------------------

it('gives a user a grant, changes it and removes it, with audit rows', function () {
    $ivy = User::factory()->create(['name' => 'Ivy', 'email' => 'ivy@console.test']);

    usAs()->postJson("/api/v1/users/{$ivy->id}/grants", ['domain' => 'bücher.example', 'role' => 'viewer'])
        ->assertCreated()
        ->assertJson(['domain' => 'xn--bcher-kva.example', 'domain_unicode' => 'bücher.example', 'role' => 'viewer', 'granted_by_name' => 'Ada Admin']);
    usAs()->postJson("/api/v1/users/{$ivy->id}/grants", ['domain' => 'xn--bcher-kva.example', 'role' => 'manager'])
        ->assertOk()->assertJsonPath('role', 'manager');
    usAs()->postJson("/api/v1/users/{$ivy->id}/grants", ['domain' => 'xn--bcher-kva.example', 'role' => 'manager'])
        ->assertOk()->assertJsonPath('role', 'manager');
    usAs()->deleteJson("/api/v1/users/{$ivy->id}/grants/bücher.example")->assertNoContent();

    expect(DomainGrant::query()->where('user_id', $ivy->id)->exists())->toBeFalse();
    expect(AuditEntry::query()->orderBy('id')->get()->map->only(['action', 'object_key', 'domain_name', 'changes'])->all())->toBe([
        ['action' => 'grant.created', 'object_key' => 'ivy@console.test', 'domain_name' => 'xn--bcher-kva.example', 'changes' => ['role' => 'viewer']],
        ['action' => 'grant.changed', 'object_key' => 'ivy@console.test', 'domain_name' => 'xn--bcher-kva.example', 'changes' => ['role' => ['viewer', 'manager']]],
        ['action' => 'grant.removed', 'object_key' => 'ivy@console.test', 'domain_name' => 'xn--bcher-kva.example', 'changes' => ['role' => 'manager']],
    ]);
});

it('answers 404 when removing a grant the user does not have', function () {
    usAs()->deleteJson("/api/v1/users/{$this->manager->id}/grants/other.org")->assertNotFound();
    usAs()->deleteJson("/api/v1/users/{$this->manager->id}/grants/nowhere.org")->assertNotFound();

    expect(AuditEntry::query()->count())->toBe(0);
});

it('refuses a grant on an unknown domain or with an unknown role', function (array $body) {
    usAs()->postJson("/api/v1/users/{$this->manager->id}/grants", $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect(AuditEntry::query()->count())->toBe(0);
})->with([
    'unknown domain' => [['domain' => 'nowhere.org', 'role' => 'viewer']],
    'malformed domain' => [['domain' => 'not a domain', 'role' => 'viewer']],
    'no domain' => [['role' => 'viewer']],
    'admin role' => [['domain' => 'users.org', 'role' => 'admin']],
]);

it('refuses a grant to a server admin, who has every domain', function () {
    $ben = usAdmin('ben@console.test');

    usAs()->postJson("/api/v1/users/{$ben->id}/grants", ['domain' => 'users.org', 'role' => 'viewer'])
        ->assertStatus(409)->assertJsonPath('code', 'already_has_access');

    expect(DomainGrant::query()->where('user_id', $ben->id)->exists())->toBeFalse();
});

it('asks for step-up before changing grants', function () {
    usAs(stepUp: false)->postJson("/api/v1/users/{$this->manager->id}/grants", ['domain' => 'other.org', 'role' => 'viewer'])->assertStatus(423);
    usAs(stepUp: false)->deleteJson("/api/v1/users/{$this->manager->id}/grants/users.org")->assertStatus(423);

    expect(DomainGrant::query()->where('user_id', $this->manager->id)->count())->toBe(1);
});

// --- Two-factor reset ----------------------------------------------------------------------------

it('resets another user\'s two-factor, audits it and emails them', function () {
    tfaEnrol($this->manager);

    usAs()->postJson("/api/v1/users/{$this->manager->id}/two-factor/reset")->assertNoContent();

    $fresh = $this->manager->refresh();
    expect($fresh->two_factor_secret)->toBeNull()
        ->and($fresh->two_factor_recovery_codes)->toBeNull()
        ->and($fresh->two_factor_confirmed_at)->toBeNull();
    expect(AuditEntry::query()->sole()->only(['action', 'actor_type', 'actor_id', 'object_type', 'object_key', 'changes']))->toBe([
        'action' => 'user.two_factor_reset', 'actor_type' => 'user', 'actor_id' => (string) $this->admin->id,
        'object_type' => 'user', 'object_key' => 'mia@console.test', 'changes' => null,
    ]);
    Mail::assertQueued(SecurityNotice::class, fn (SecurityNotice $mail) => $mail->kind === 'two_factor_disabled'
        && $mail->hasTo('mia@console.test') && $mail->locale === 'fi' && $mail->ip === '127.0.0.1');
});

it('a server admin no longer active resets nobody\'s two-factor, even with a request authorised earlier', function () {
    $ben = usAdmin('ben@console.test');
    tfaEnrol($this->manager);
    DB::table('users')->where('id', $ben->id)->update(['status' => 'deactivated']);

    usAs($ben)->postJson("/api/v1/users/{$this->manager->id}/two-factor/reset")->assertNotFound();

    expect($this->manager->refresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('answers 409 when the user has no confirmed two-factor', function () {
    usAs()->postJson("/api/v1/users/{$this->manager->id}/two-factor/reset")
        ->assertStatus(409)->assertJsonPath('code', 'two_factor_not_enabled');

    expect(AuditEntry::query()->count())->toBe(0);
    Mail::assertNothingQueued();
});

it('asks for step-up before resetting two-factor', function () {
    tfaEnrol($this->manager);

    usAs(stepUp: false)->postJson("/api/v1/users/{$this->manager->id}/two-factor/reset")->assertStatus(423);

    expect($this->manager->refresh()->two_factor_confirmed_at)->not->toBeNull();
});
