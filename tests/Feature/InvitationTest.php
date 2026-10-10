<?php

use App\Auth\DomainAccess;
use App\Auth\Invitations;
use App\Mail\InvitationMail;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use App\MailServer\Repositories\DomainRepository;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GuideFixture;

// Invitations (plan 2c, Task 4): an emailed link that gives a new or existing console user a role on
// one domain, or makes them a server admin. inv.org (id 70) has the manager Mia and the viewer Vic;
// other.org (id 71) has nobody; bücher.example (id 72) is an IDN domain.

beforeEach(function () {
    config(['emailsquire.require_two_factor' => 'off', 'emailsquire.managers_can_share' => false]);
    GuideFixture::load('trixie');
    invGuide('virtual_domains')->insert([
        ['id' => 70, 'name' => 'inv.org'], ['id' => 71, 'name' => 'other.org'], ['id' => 72, 'name' => 'xn--bcher-kva.example'],
    ]);

    $this->admin = User::factory()->create(['name' => 'Ada Admin', 'email' => 'ada@console.test', 'is_server_admin' => true, 'password_set_at' => now()]);
    $this->manager = User::factory()->create(['name' => 'Mia Manager', 'email' => 'mia@console.test', 'password_set_at' => now()]);
    $this->viewer = User::factory()->create(['name' => 'Vic Viewer', 'email' => 'vic@console.test', 'password_set_at' => now()]);
    invGrant($this->manager, 70, 'inv.org', 'manager');
    invGrant($this->viewer, 70, 'inv.org', 'viewer');
});

function invGuide(string $table): Builder
{
    return DB::table(GuideSchema::table($table));
}

function invGrant(User $user, int $domainId, string $domainName, string $role): DomainGrant
{
    return DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => $domainId, 'domain_name' => $domainName, 'role' => $role]);
}

/** Invites through the domain's Access API as $as (the admin by default), step-up confirmed. */
function invCreate(array $body = [], string $domain = 'inv.org', ?User $as = null)
{
    test()->actingAs($as ?? test()->admin);
    confirmStepUp();

    return test()->postJson("/api/v1/domains/{$domain}/access/invitations", array_merge([
        'email' => 'newbie@example.net',
        'role' => 'viewer',
        'locale' => 'en',
    ], $body));
}

/** Invites through POST /users/invitations as the admin. */
function invCreateServer(array $body = [])
{
    test()->actingAs(test()->admin);
    confirmStepUp();

    return test()->postJson('/api/v1/users/invitations', array_merge([
        'email' => 'boss@example.net',
        'role' => 'server_admin',
        'locale' => 'en',
    ], $body));
}

/** The token in the newest queued invitation mail's link. */
function invLastToken(): string
{
    $token = null;
    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) use (&$token) {
        $token = invTokenFromUrl($mail->url);

        return true;
    });

    return (string) $token;
}

function invTokenFromUrl(string $url): ?string
{
    return preg_match('#/invite/([A-Za-z0-9_-]+)$#', $url, $m) === 1 ? $m[1] : null;
}

/** Creates an invitation as $as and returns [invitation, token]; leaves the test signed out. */
function invIssue(array $body = [], string $domain = 'inv.org', ?User $as = null): array
{
    Mail::fake();
    $id = invCreate($body, $domain, $as)->assertCreated()->json('id');
    $token = invLastToken();
    invSignOut();

    return [Invitation::query()->findOrFail($id), $token];
}

function invSignOut(): void
{
    auth()->guard('web')->logout();
    test()->flushSession();
}

function invAccept(string $token, array $body = [])
{
    return test()->postJson("/api/v1/auth/invitations/{$token}/accept", $body);
}

function invNewPerson(array $over = []): array
{
    return array_merge(['name' => 'Nina Newbie', 'password' => 'a long new passphrase 7', 'password_confirmation' => 'a long new passphrase 7'], $over);
}

function invRole(User|int $user, int $domainId = 70): ?string
{
    $id = $user instanceof User ? $user->id : $user;
    $role = DomainGrant::query()->where('user_id', $id)->where('domain_id', $domainId)->value('role');

    return is_string($role) ? $role : null;
}

// --- Create --------------------------------------------------------------------------------------

it('creates a domain invitation, stores only the token hash and queues the mail with the link', function () {
    Mail::fake();

    $response = invCreate(['email' => 'Newbie@Example.NET', 'note' => 'Welcome aboard', 'name' => 'Nina'])->assertCreated();

    $invitation = Invitation::query()->sole();
    $response->assertExactJson([
        'id' => $invitation->id,
        'email' => 'newbie@example.net',
        'role' => 'viewer',
        'domain' => 'inv.org',
        'state' => 'valid',
        'expires_at' => $invitation->expires_at->toIso8601String(),
    ]);
    expect($invitation->expires_at->diffInHours(now()->addHours(72), true))->toBeLessThan(1)
        ->and($invitation->invited_by)->toBe($this->admin->id)
        ->and($invitation->only(['domain_id', 'domain_name', 'note', 'name', 'locale']))
        ->toBe(['domain_id' => 70, 'domain_name' => 'inv.org', 'note' => 'Welcome aboard', 'name' => 'Nina', 'locale' => 'en']);

    $token = invLastToken();
    expect(strlen($token))->toBe(43)
        ->and($invitation->token_hash)->toBe(hash('sha256', $token))
        ->and($invitation->token_hash)->not->toBe($token);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->hasTo('newbie@example.net')
        && $mail->locale === 'en'
        && $mail->url === rtrim(config('app.url'), '/').'/invite/'.$token);
});

it('audits user.invited with the role and no token or hash anywhere in the row', function () {
    Mail::fake();
    invCreate()->assertCreated();
    $token = invLastToken();

    $audit = AuditEntry::query()->where('action', 'user.invited')->sole();
    expect($audit->only(['actor_type', 'actor_id', 'object_type', 'object_key', 'domain_name', 'changes']))->toBe([
        'actor_type' => 'user', 'actor_id' => (string) $this->admin->id, 'object_type' => 'invitation',
        'object_key' => 'newbie@example.net', 'domain_name' => 'inv.org', 'changes' => ['role' => 'viewer'],
    ]);
    $row = json_encode(DB::table('audit_log')->where('id', $audit->id)->first());
    expect($row)->not->toContain($token)->not->toContain(hash('sha256', $token))->not->toMatch('/"[^"]*(token|hash)[^"]*":/i');
});

it('sends the mail in the invitation language: a Finnish invitation has the Finnish subject', function () {
    Mail::fake();

    invCreate(['locale' => 'fi'])->assertCreated();

    Mail::assertQueued(InvitationMail::class, function (InvitationMail $mail) {
        $html = $mail->locale('fi')->render();

        return $mail->locale === 'fi'
            && str_contains($html, 'Sinut on kutsuttu EmailSquireen')
            && str_contains($html, 'voimassa 72 tuntia');
    });
});

it('writes a mail naming the inviter, the unicode domain, the role, the note as plain text and the 72 hours', function () {
    $mail = (new InvitationMail(
        url: 'https://console.example/invite/abc',
        inviterName: 'Ada Admin',
        recipientName: 'Nina',
        role: 'manager',
        domainUnicode: 'bücher.example',
        note: "<b>Hi</b> [click](http://evil.example) *now*\n\nsecond line",
    ))->locale('en');

    $mail->assertSeeInHtml('Ada Admin')
        ->assertSeeInHtml('bücher.example')
        ->assertSeeInHtml('Domain manager')
        ->assertSeeInHtml('valid for 72 hours')
        ->assertSeeInHtml('https://console.example/invite/abc')
        ->assertSeeInHtml('&lt;b&gt;Hi&lt;/b&gt;', false)
        ->assertDontSeeInHtml('<b>Hi</b>', false)
        ->assertDontSeeInHtml('href="http://evil.example', false)
        ->assertDontSeeInHtml('<em>', false)
        ->assertDontSeeInHtml('<img', false)
        ->assertSeeInText('Ada Admin')
        ->assertSeeInText('bücher.example')
        ->assertSeeInText('[click](http://evil.example) *now*')
        ->assertSeeInText('second line')
        ->assertSeeInText('valid for 72 hours')
        ->assertSeeInText('https://console.example/invite/abc');
    expect($mail->envelope()->subject)->toBe('You’re invited to EmailSquire');

    $server = (new InvitationMail('https://console.example/invite/x', 'Ada Admin', null, 'server_admin', null, null))->locale('en');
    $server->assertSeeInHtml('the whole server')->assertSeeInHtml('Server admin')->assertDontSeeInHtml('Message from');
});

it('builds the link from APP_URL, never from the request host', function () {
    Mail::fake();
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->postJson('http://evil.example/api/v1/domains/inv.org/access/invitations', ['email' => 'a@example.net', 'role' => 'viewer', 'locale' => 'en'])
        ->assertCreated();

    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => str_starts_with($mail->url, rtrim(config('app.url'), '/').'/invite/')
        && ! str_contains($mail->url, 'evil.example'));
});

it('queues the mail encrypted, so the token is not readable in the jobs table', function () {
    config(['queue.default' => 'database']);

    invCreate()->assertCreated();

    $payload = (string) DB::table('jobs')->sole()->payload;
    $command = json_decode($payload, true)['data']['command'];
    $plain = str_starts_with($command, 'O:') ? $command : decrypt($command);
    $token = preg_match('#/invite/([A-Za-z0-9_-]{43})#', $plain, $m) === 1 ? $m[1] : null;
    expect($token)->not->toBeNull()
        ->and(hash('sha256', (string) $token))->toBe(Invitation::query()->sole()->token_hash)
        ->and($payload)->not->toContain((string) $token)
        ->and($payload)->not->toContain('/invite/');
});

it('retries a queued invitation three times with a backoff', function () {
    Queue::fake();

    invCreate()->assertCreated();

    Queue::assertPushed(SendQueuedMailable::class, fn (SendQueuedMailable $job) => $job->tries === 3 && $job->backoff() === [30, 120]);
});

it('invites a server admin through the users API, with no domain', function () {
    Mail::fake();

    invCreateServer()->assertCreated()->assertJsonPath('domain', null)->assertJsonPath('role', 'server_admin');

    $audit = AuditEntry::query()->where('action', 'user.invited')->sole();
    expect($audit->domain_name)->toBeNull()->and($audit->changes)->toBe(['role' => 'server_admin']);
});

it('invites to a domain through the users API, which needs a known domain for a domain role', function () {
    Mail::fake();

    invCreateServer(['email' => 'x@example.net', 'role' => 'manager', 'domain' => 'XN--BCHER-KVA.example'])->assertCreated()
        ->assertJsonPath('domain', 'xn--bcher-kva.example');
    invCreateServer(['email' => 'y@example.net', 'role' => 'manager'])->assertStatus(422)->assertJsonStructure(['errors' => ['domain']]);
    invCreateServer(['email' => 'y@example.net', 'role' => 'viewer', 'domain' => 'nowhere.org'])->assertStatus(422)->assertJsonStructure(['errors' => ['domain']]);
    invCreateServer(['email' => 'y@example.net', 'role' => 'owner'])->assertStatus(422)->assertJsonStructure(['errors' => ['role']]);
    expect(Invitation::query()->count())->toBe(1);
});

it('validates the create body', function (array $body, string $field) {
    invCreate($body)->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => [$field]]);
    expect(Invitation::query()->count())->toBe(0);
})->with([
    'bad email' => [['email' => 'not an email'], 'email'],
    'server admin on a domain' => [['role' => 'server_admin'], 'role'],
    'unknown locale' => [['locale' => 'sv'], 'locale'],
    'missing locale' => [['locale' => null], 'locale'],
    'long note' => [['note' => str_repeat('a', 501)], 'note'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
]);

// --- Refusals ------------------------------------------------------------------------------------

it('refuses an email that already has access, a server admin and a duplicate pending invitation', function () {
    Mail::fake();

    invCreate(['email' => 'VIC@console.test'])->assertStatus(409)->assertJsonPath('code', 'already_has_access')
        ->assertHeader('Content-Type', 'application/problem+json');
    invCreate(['email' => 'ada@console.test'])->assertStatus(409)->assertJsonPath('code', 'already_has_access');
    invCreateServer(['email' => 'ada@console.test'])->assertStatus(409)->assertJsonPath('code', 'already_has_access');

    invCreate(['email' => 'dup@example.net'])->assertCreated();
    invCreate(['email' => 'Dup@example.net', 'role' => 'manager'])->assertStatus(409)->assertJsonPath('code', 'already_has_access');
    // Another target is not a duplicate.
    invCreate(['email' => 'dup@example.net'], 'other.org')->assertCreated();

    expect(Invitation::query()->count())->toBe(2);
    Mail::assertQueued(InvitationMail::class, 2);
});

it('does not count an expired or revoked invitation as a duplicate', function () {
    [$expired] = invIssue(['email' => 'again@example.net']);
    $this->travel(73)->hours();
    Mail::fake();

    invCreate(['email' => 'again@example.net'])->assertCreated();
    $second = Invitation::query()->latest('id')->firstOrFail();
    app(Invitations::class)->revoke($second, $this->admin);
    invCreate(['email' => 'again@example.net'])->assertCreated();

    expect(Invitation::query()->count())->toBe(3)->and($expired->id)->not->toBe($second->id);
});

it('refuses the email of a deactivated user with 409 user_deactivated', function () {
    Mail::fake();
    User::factory()->create(['email' => 'gone@console.test', 'status' => User::STATUS_DEACTIVATED, 'password_set_at' => now()]);

    invCreate(['email' => 'gone@console.test'])->assertStatus(409)->assertJsonPath('code', 'user_deactivated');
    invCreateServer(['email' => 'Gone@console.test'])->assertStatus(409)->assertJsonPath('code', 'user_deactivated');

    Mail::assertNothingQueued();
});

// --- Lookup --------------------------------------------------------------------------------------

it('shows a valid invitation to its token holder, signed out', function () {
    $this->admin->forceFill(['name' => 'Ada Admin'])->save();
    [, $token] = invIssue(['email' => 'nina@example.net', 'role' => 'manager', 'note' => 'Hi Nina', 'name' => 'Nina'], 'xn--bcher-kva.example');

    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertExactJson([
        'email' => 'nina@example.net',
        'name' => 'Nina',
        'inviter_name' => 'Ada Admin',
        'domain' => 'xn--bcher-kva.example',
        'domain_unicode' => 'bücher.example',
        'role' => 'manager',
        'note' => 'Hi Nina',
        'state' => 'valid',
        'existing_user' => false,
    ]);
});

it('tells the token holder when the invited email already has an account, and shows a server invitation', function () {
    User::factory()->create(['email' => 'olga@example.net', 'password_set_at' => now()]);
    [, $token] = invIssue(['email' => 'olga@example.net']);
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertJsonPath('existing_user', true);

    Mail::fake();
    invCreateServer()->assertCreated();
    $server = invLastToken();
    invSignOut();
    $this->getJson("/api/v1/auth/invitations/{$server}")->assertOk()
        ->assertJsonPath('domain', null)->assertJsonPath('domain_unicode', null)->assertJsonPath('role', 'server_admin');
});

it('answers an unknown token with 404 invitation_not_found', function (string $token) {
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'invitation_not_found');
    invAccept($token, invNewPerson())->assertNotFound()->assertJsonPath('code', 'invitation_not_found');
})->with([
    'random' => [str_repeat('A', 43)],
    'short' => ['abc'],
]);

// --- States --------------------------------------------------------------------------------------

it('expires after 72 hours', function () {
    [$invitation, $token] = invIssue();

    $this->travel(71)->hours();
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertJsonPath('state', 'valid');

    $this->travel(2)->hours();
    expect(app(Invitations::class)->state($invitation->refresh()))->toBe('expired');
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertOk()->assertJsonPath('state', 'expired');
    invAccept($token, invNewPerson())->assertStatus(410)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'invitation_invalid')
        ->assertJsonPath('state', 'expired');
    expect(User::query()->where('email', 'newbie@example.net')->exists())->toBeFalse();
});

it('works once: a second accept answers 410 used', function () {
    [$invitation, $token] = invIssue();

    invAccept($token, invNewPerson())->assertOk();
    invSignOut();

    invAccept($token, invNewPerson(['name' => 'Someone Else']))->assertStatus(410)->assertJsonPath('state', 'used');
    expect(User::query()->where('email', 'newbie@example.net')->count())->toBe(1)
        ->and(app(Invitations::class)->state($invitation->refresh()))->toBe('used');
});

it('issues a new token on resend: the old link is gone and the new one works, valid for 72 h again', function () {
    [$invitation, $old] = invIssue();
    $this->travel(48)->hours();
    Mail::fake();
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->postJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}/resend")->assertOk()
        ->assertJsonPath('id', $invitation->id)->assertJsonPath('state', 'valid');
    $new = invLastToken();
    invSignOut();

    expect($new)->not->toBe($old)
        ->and($invitation->refresh()->token_hash)->toBe(hash('sha256', $new))
        ->and($invitation->expires_at->diffInMinutes(now()->addHours(72), true))->toBeLessThan(1);
    $this->getJson("/api/v1/auth/invitations/{$old}")->assertNotFound()->assertJsonPath('code', 'invitation_not_found');
    invAccept($old, invNewPerson())->assertNotFound();

    $this->travel(48)->hours();
    $this->getJson("/api/v1/auth/invitations/{$new}")->assertJsonPath('state', 'valid');
    invAccept($new, invNewPerson())->assertOk();

    $audit = AuditEntry::query()->where('action', 'invitation.resent')->sole();
    expect($audit->only(['object_type', 'object_key', 'domain_name', 'changes']))
        ->toBe(['object_type' => 'invitation', 'object_key' => 'newbie@example.net', 'domain_name' => 'inv.org', 'changes' => ['role' => 'viewer']]);
    $row = json_encode(DB::table('audit_log')->where('id', $audit->id)->first());
    expect($row)->not->toContain($new)->not->toContain($old);
});

it('answers 410 revoked for a revoked invitation and audits invitation.revoked', function () {
    [$invitation, $token] = invIssue();
    $this->actingAs($this->admin);
    confirmStepUp();

    $this->deleteJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}")->assertNoContent();
    invSignOut();

    expect($invitation->refresh()->revoked_at)->not->toBeNull();
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertJsonPath('state', 'revoked');
    invAccept($token, invNewPerson())->assertStatus(410)->assertJsonPath('state', 'revoked');
    expect(User::query()->where('email', 'newbie@example.net')->exists())->toBeFalse();
    $audit = AuditEntry::query()->where('action', 'invitation.revoked')->sole();
    expect($audit->only(['actor_id', 'object_type', 'object_key', 'domain_name']))
        ->toBe(['actor_id' => (string) $this->admin->id, 'object_type' => 'invitation', 'object_key' => 'newbie@example.net', 'domain_name' => 'inv.org']);
});

it('revokes the pending invitations of a deleted domain and creates nothing on accept', function () {
    [$invitation, $token] = invIssue();
    [$elsewhere] = invIssue(['email' => 'keep@example.net'], 'other.org');

    $this->actingAs($this->admin);
    app(DomainRepository::class)->delete(Domain::query()->findOrFail(70));
    invSignOut();

    expect($invitation->refresh()->revoked_at)->not->toBeNull()
        ->and($elsewhere->refresh()->revoked_at)->toBeNull();
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertJsonPath('state', 'revoked');
    invAccept($token, invNewPerson())->assertStatus(410)->assertJsonPath('state', 'revoked');
    expect(User::query()->where('email', 'newbie@example.net')->exists())->toBeFalse()
        ->and(DomainGrant::query()->count())->toBe(0);
});

it('counts as revoked when the domain is gone or renamed outside the console', function (string $change) {
    [$invitation, $token] = invIssue();
    match ($change) {
        'gone' => invGuide('virtual_domains')->where('id', 70)->delete(),
        'renamed' => invGuide('virtual_domains')->where('id', 70)->update(['name' => 'renamed.org']),
    };

    expect(app(Invitations::class)->state($invitation->refresh()))->toBe('revoked');
    invAccept($token, invNewPerson())->assertStatus(410)->assertJsonPath('state', 'revoked');
    expect(User::query()->where('email', 'newbie@example.net')->exists())->toBeFalse();
})->with(['gone', 'renamed']);

it('counts as revoked when the inviter can no longer share the domain, and that accept creates nothing', function (string $change) {
    config(['emailsquire.managers_can_share' => true]);
    [$invitation, $token] = invIssue([], 'inv.org', $this->manager);

    match ($change) {
        'grant removed' => DomainGrant::query()->where('user_id', $this->manager->id)->delete(),
        'demoted to viewer' => DomainGrant::query()->where('user_id', $this->manager->id)->update(['role' => 'viewer']),
        'sharing turned off' => config(['emailsquire.managers_can_share' => false]),
        'deactivated' => $this->manager->forceFill(['status' => User::STATUS_DEACTIVATED])->save(),
        'deleted' => $this->manager->delete(),
    };

    expect(app(Invitations::class)->state($invitation->refresh()))->toBe('revoked');
    $this->getJson("/api/v1/auth/invitations/{$token}")->assertJsonPath('state', 'revoked');
    invAccept($token, invNewPerson())->assertStatus(410)->assertJsonPath('code', 'invitation_invalid')->assertJsonPath('state', 'revoked');
    expect(User::query()->where('email', 'newbie@example.net')->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'invitation.accepted')->exists())->toBeFalse();
})->with(['grant removed', 'demoted to viewer', 'sharing turned off', 'deactivated', 'deleted']);

it('counts a server-admin invitation as revoked once its inviter is no longer a server admin', function () {
    Mail::fake();
    invCreateServer()->assertCreated();
    $token = invLastToken();
    invSignOut();

    $this->admin->forceFill(['is_server_admin' => false])->save();

    invAccept($token, invNewPerson())->assertStatus(410)->assertJsonPath('state', 'revoked');
    expect(User::query()->where('email', 'boss@example.net')->exists())->toBeFalse();
});

it('evaluates the inviter without changing what the signed-in user may do in the same request', function () {
    config(['emailsquire.managers_can_share' => true]);
    [$invitation] = invIssue([], 'inv.org', $this->manager);
    $access = app(DomainAccess::class);
    $domain = Domain::query()->findOrFail(70);

    // The viewer's role is read and memoised first; checking the manager inviter must not change it.
    expect($access->role($this->viewer, $domain))->toBe('viewer')
        ->and(app(Invitations::class)->state($invitation))->toBe('valid')
        ->and($access->role($this->viewer, $domain))->toBe('viewer')
        ->and($access->canShare($this->viewer, $domain))->toBeFalse();
});

// --- Accept --------------------------------------------------------------------------------------

it('creates a new person with the grant, in the invitation language, and signs them in', function () {
    [$invitation, $token] = invIssue(['locale' => 'fi', 'role' => 'manager']);

    $response = invAccept($token, invNewPerson())->assertOk();

    $user = User::query()->where('email', 'newbie@example.net')->sole();
    $response->assertJsonPath('id', $user->id)->assertJsonPath('email', 'newbie@example.net')
        ->assertJsonPath('name', 'Nina Newbie')->assertJsonPath('locale', 'fi')->assertJsonPath('landing', 'inv.org');
    expect($user->isActive())->toBeTrue()
        ->and($user->is_server_admin)->toBeFalse()
        ->and($user->locale)->toBe('fi')
        ->and($user->password_set_at)->not->toBeNull()
        ->and(Hash::check('a long new passphrase 7', $user->password))->toBeTrue()
        ->and(invRole($user))->toBe('manager')
        ->and(DomainGrant::query()->where('user_id', $user->id)->value('granted_by'))->toBe($this->admin->id)
        ->and($invitation->refresh()->accepted_at)->not->toBeNull();

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'newbie@example.net');
    $this->getJson('/api/v1/domains/inv.org/mailboxes')->assertOk();

    $accepted = AuditEntry::query()->where('action', 'invitation.accepted')->sole();
    expect($accepted->only(['actor_type', 'actor_id', 'object_type', 'object_key', 'domain_name', 'changes']))->toBe([
        'actor_type' => 'user', 'actor_id' => (string) $user->id, 'object_type' => 'invitation',
        'object_key' => 'newbie@example.net', 'domain_name' => 'inv.org', 'changes' => ['role' => 'manager'],
    ]);
    $grant = AuditEntry::query()->where('action', 'grant.created')->sole();
    expect($grant->actor_id)->toBe((string) $user->id)->and($grant->object_key)->toBe('newbie@example.net');
    $rows = json_encode(DB::table('audit_log')->get());
    expect($rows)->not->toContain($token)->not->toContain('passphrase');
});

it('checks a new person against the setup password rules and keeps the invitation on a refusal', function (array $over, string $field) {
    [$invitation, $token] = invIssue();

    invAccept($token, invNewPerson($over))->assertStatus(422)->assertJsonPath('code', 'validation_failed')->assertJsonStructure(['errors' => [$field]]);

    expect(User::query()->where('email', 'newbie@example.net')->exists())->toBeFalse()
        ->and(app(Invitations::class)->state($invitation->refresh()))->toBe('valid');
    $this->getJson('/api/v1/me')->assertUnauthorized();
})->with([
    'short password' => [['password' => 'short pass1', 'password_confirmation' => 'short pass1'], 'password'],
    'confirmation differs' => [['password_confirmation' => 'something else entirely'], 'password'],
    'no name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('n', 101)], 'name'],
]);

it('gives an existing user who is signed in as that email the grant, without signing anyone in again', function () {
    $olga = User::factory()->create(['email' => 'olga@example.net', 'password_set_at' => now()]);
    [$invitation, $token] = invIssue(['email' => 'Olga@Example.net']);
    $this->actingAs($olga);

    invAccept($token)->assertOk()->assertJsonPath('id', $olga->id)->assertJsonPath('landing', 'inv.org');

    expect(invRole($olga))->toBe('viewer')
        ->and($invitation->refresh()->accepted_at)->not->toBeNull()
        ->and(User::query()->count())->toBe(4);
});

it('asks an existing user to sign in, and refuses another account', function () {
    $olga = User::factory()->create(['email' => 'olga@example.net', 'password_set_at' => now()]);
    [$invitation, $token] = invIssue(['email' => 'olga@example.net']);

    invAccept($token)->assertStatus(409)->assertJsonPath('code', 'sign_in_required')
        ->assertHeader('Content-Type', 'application/problem+json');
    // A new password for the existing account must not work either.
    invAccept($token, invNewPerson())->assertStatus(409)->assertJsonPath('code', 'sign_in_required');
    $this->getJson('/api/v1/me')->assertUnauthorized();

    $this->actingAs($this->viewer);
    invAccept($token)->assertStatus(409)->assertJsonPath('code', 'wrong_account');

    // A deactivated session of the right user is not signed in.
    $olga->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
    $this->actingAs($olga->refresh());
    invAccept($token)->assertStatus(409)->assertJsonPath('code', 'sign_in_required');

    expect(invRole($olga))->toBeNull()
        ->and(app(Invitations::class)->state($invitation->refresh()))->toBe('valid')
        ->and(Hash::check('a long new passphrase 7', $olga->refresh()->password))->toBeFalse();
});

it('refuses a new-person accept from a browser signed in as someone else', function () {
    [, $token] = invIssue();
    $this->actingAs($this->viewer);

    invAccept($token, invNewPerson())->assertStatus(409)->assertJsonPath('code', 'wrong_account');

    expect(User::query()->where('email', 'newbie@example.net')->exists())->toBeFalse();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'vic@console.test');
});

it('makes a new server admin, audits user.role_changed, and holds them at two-factor setup', function () {
    Mail::fake();
    invCreateServer()->assertCreated();
    $token = invLastToken();
    invSignOut();
    config(['emailsquire.require_two_factor' => 'admins']);

    invAccept($token, invNewPerson())->assertOk()->assertJsonPath('is_server_admin', true)->assertJsonPath('two_factor_required', true);

    $boss = User::query()->where('email', 'boss@example.net')->sole();
    expect($boss->is_server_admin)->toBeTrue()->and($boss->two_factor_confirmed_at)->toBeNull();
    $audit = AuditEntry::query()->where('action', 'user.role_changed')->sole();
    expect($audit->only(['actor_id', 'object_type', 'object_key', 'domain_name', 'changes']))->toBe([
        'actor_id' => (string) $boss->id, 'object_type' => 'user', 'object_key' => 'boss@example.net', 'domain_name' => null,
        'changes' => ['is_server_admin' => [false, true]],
    ]);
    expect(AuditEntry::query()->where('action', 'invitation.accepted')->sole()->changes)->toBe(['role' => 'server_admin']);

    $this->getJson('/api/v1/dashboard')->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    $this->getJson('/api/v1/me')->assertOk();
});

it('promotes an existing signed-in user with a server-admin invitation and clears their grants', function () {
    Mail::fake();
    invCreateServer(['email' => 'vic@console.test'])->assertCreated();
    $token = invLastToken();
    invSignOut();
    $this->actingAs($this->viewer);

    invAccept($token)->assertOk()->assertJsonPath('is_server_admin', true);

    expect($this->viewer->refresh()->is_server_admin)->toBeTrue()
        ->and(DomainGrant::query()->where('user_id', $this->viewer->id)->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('action', 'user.role_changed')->sole()->changes)
        ->toBe(['is_server_admin' => [false, true], 'grants_removed' => 1]);
});

it('locks the domain row and then the invitation row FOR UPDATE when accepting', function () {
    [, $token] = invIssue();
    $queries = [];
    Event::listen(QueryExecuted::class, function (QueryExecuted $q) use (&$queries) {
        $queries[] = strtolower($q->sql);
    });

    invAccept($token, invNewPerson())->assertOk();

    $domain = collect($queries)->search(fn (string $sql) => str_contains($sql, 'virtual_domains') && str_contains($sql, 'lock in share mode'));
    $invitation = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from `invitations`') && str_contains($sql, 'for update'));
    expect($domain)->not->toBeFalse()->and($invitation)->not->toBeFalse()->and($domain)->toBeLessThan($invitation);
});

// --- Access list ---------------------------------------------------------------------------------

it('lists the pending and expired invitations on the Access tab, without accepted or revoked ones', function () {
    $this->travelTo('2026-10-01 09:00:00');
    [$pending] = invIssue(['email' => 'pending@example.net', 'role' => 'manager']);
    [$expired] = invIssue(['email' => 'expired@example.net']);
    [$accepted, $acceptedToken] = invIssue(['email' => 'accepted@example.net']);
    [$revoked] = invIssue(['email' => 'revoked@example.net']);
    invIssue(['email' => 'elsewhere@example.net'], 'other.org');
    $expired->forceFill(['expires_at' => now()->subHour()])->save();
    invAccept($acceptedToken, invNewPerson())->assertOk();
    invSignOut();
    app(Invitations::class)->revoke($revoked, $this->admin);
    $this->actingAs($this->viewer);

    $this->getJson('/api/v1/domains/inv.org/access')->assertOk()->assertJsonPath('invitations', [
        [
            'id' => $expired->id, 'email' => 'expired@example.net', 'role' => 'viewer', 'invited_by_name' => 'Ada Admin',
            'created_at' => '2026-10-01T09:00:00+00:00', 'expires_at' => '2026-10-01T08:00:00+00:00', 'state' => 'expired',
        ],
        [
            'id' => $pending->id, 'email' => 'pending@example.net', 'role' => 'manager', 'invited_by_name' => 'Ada Admin',
            'created_at' => '2026-10-01T09:00:00+00:00', 'expires_at' => '2026-10-04T09:00:00+00:00', 'state' => 'valid',
        ],
    ]);
});

// --- Management ----------------------------------------------------------------------------------

it('answers 404 on the domain routes for an invitation of another domain or of the server', function () {
    [$elsewhere] = invIssue(['email' => 'x@example.net'], 'other.org');
    Mail::fake();
    invCreateServer()->assertCreated();
    $server = Invitation::query()->where('role', 'server_admin')->sole();
    $this->actingAs($this->admin);
    confirmStepUp();

    foreach ([$elsewhere, $server] as $invitation) {
        $this->postJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}/resend")->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->deleteJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}")->assertNotFound();
    }
    $this->deleteJson('/api/v1/domains/inv.org/access/invitations/999999')->assertNotFound();

    // The users routes reach any invitation.
    $this->postJson("/api/v1/users/invitations/{$elsewhere->id}/resend")->assertOk();
    $this->deleteJson("/api/v1/users/invitations/{$server->id}")->assertNoContent();
    expect($server->refresh()->revoked_at)->not->toBeNull();
});

it('re-attributes a resent invitation to whoever resent it, which revives one whose inviter left', function () {
    config(['emailsquire.managers_can_share' => true]);
    [$invitation, $old] = invIssue([], 'inv.org', $this->manager);
    $this->manager->forceFill(['status' => User::STATUS_DEACTIVATED])->save();
    expect(app(Invitations::class)->state($invitation->refresh()))->toBe('revoked');

    Mail::fake();
    $this->actingAs($this->admin);
    confirmStepUp();
    $this->postJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}/resend")->assertOk()->assertJsonPath('state', 'valid');

    expect($invitation->refresh()->invited_by)->toBe($this->admin->id);
    Mail::assertQueued(InvitationMail::class, fn (InvitationMail $mail) => $mail->inviterName === 'Ada Admin');
});

it('refuses to resend or revoke an accepted or revoked invitation with 409 invitation_closed', function () {
    [$accepted, $token] = invIssue();
    invAccept($token, invNewPerson())->assertOk();
    invSignOut();
    [$revoked] = invIssue(['email' => 'r@example.net']);
    app(Invitations::class)->revoke($revoked, $this->admin);
    $this->actingAs($this->admin);
    confirmStepUp();

    foreach ([$accepted, $revoked] as $invitation) {
        $this->postJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}/resend")->assertStatus(409)->assertJsonPath('code', 'invitation_closed');
        $this->deleteJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}")->assertStatus(409)->assertJsonPath('code', 'invitation_closed');
    }
});

it('needs step-up to create, resend or revoke', function () {
    [$invitation] = invIssue();
    $this->actingAs($this->admin);

    $this->postJson('/api/v1/domains/inv.org/access/invitations', ['email' => 'z@example.net', 'role' => 'viewer', 'locale' => 'en'])
        ->assertStatus(423)->assertJsonPath('code', 'step_up_required');
    $this->postJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}/resend")->assertStatus(423);
    $this->deleteJson("/api/v1/domains/inv.org/access/invitations/{$invitation->id}")->assertStatus(423);
    $this->postJson('/api/v1/users/invitations', ['email' => 'z@example.net', 'role' => 'server_admin', 'locale' => 'en'])->assertStatus(423);
    $this->postJson("/api/v1/users/invitations/{$invitation->id}/resend")->assertStatus(423);
    $this->deleteJson("/api/v1/users/invitations/{$invitation->id}")->assertStatus(423);
});

// --- Limits --------------------------------------------------------------------------------------

it('allows 30 invitations an hour per user, resends included, then answers 429', function () {
    Mail::fake();
    foreach (range(1, 29) as $i) {
        invCreate(['email' => "u{$i}@example.net"])->assertCreated();
    }
    $last = Invitation::query()->latest('id')->firstOrFail();
    $this->postJson("/api/v1/domains/inv.org/access/invitations/{$last->id}/resend")->assertOk();

    invCreate(['email' => 'u31@example.net'])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
    $this->postJson("/api/v1/users/invitations/{$last->id}/resend")->assertStatus(429);
    expect(Invitation::query()->count())->toBe(29);

    // The hour passes.
    $this->travel(61)->minutes();
    invCreate(['email' => 'u32@example.net'])->assertCreated();
});

it('allows 30 lookups and 10 accepts a minute per IP address', function () {
    foreach (range(1, 30) as $i) {
        $this->getJson('/api/v1/auth/invitations/'.str_repeat('x', 43))->assertNotFound();
    }
    $this->getJson('/api/v1/auth/invitations/'.str_repeat('x', 43))->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    foreach (range(1, 10) as $i) {
        invAccept(str_repeat('y', 43), invNewPerson())->assertNotFound();
    }
    invAccept(str_repeat('y', 43), invNewPerson())->assertStatus(429);
});
