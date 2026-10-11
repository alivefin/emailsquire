<?php

use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\GuideSchema;
use App\MailServer\SelfService;
use App\Models\DomainGrant;
use App\Models\DomainSetting;
use App\Models\MailboxUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

// GET /portal/me: what the portal's home, settings and help screens show about the signed-in mailbox.

beforeEach(function () {
    GuideFixture::load('trixie'); // example.org, id 10
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'xn--exmple-cua.fi']);
    DB::table(GuideSchema::table('virtual_users'))->insert([
        ['domain_id' => 10, 'email' => 'anna@example.org', 'password' => '{BLF-CRYPT}x', 'quota' => 2147483648],
        ['domain_id' => 11, 'email' => 'eero@xn--exmple-cua.fi', 'password' => '{BLF-CRYPT}x', 'quota' => 0],
    ]);
    app(PasswordChecker::class)->accept('anna@example.org', 'mailbox passphrase 2')->accept('eero@xn--exmple-cua.fi', 'mailbox passphrase 2');
    config([
        'emailsquire.mail_host' => 'mail.example.org',
        'emailsquire.webmail_url' => 'https://webmail.example.org/',
        'emailsquire.support_contact' => '',
    ]);
});

function pmSignIn(string $email = 'anna@example.org'): void
{
    test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => 'mailbox passphrase 2'])->assertOk();
}

function pmGrant(string $email, string $name, string $role, array $attributes = [], string $domainName = 'example.org', int $domainId = 10): User
{
    $user = User::factory()->create(array_merge(['email' => $email, 'name' => $name, 'password_set_at' => now()], $attributes));
    DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => $domainId, 'domain_name' => $domainName, 'role' => $role]);

    return $user;
}

it('answers the mailbox, its storage, switches, people and client settings', function () {
    MailboxUsage::query()->forceCreate(['email' => 'anna@example.org', 'used_kib' => 1024, 'limit_kib' => 2097152, 'checked_at' => now()]);
    pmGrant('maija@console.test', 'Maija Manager', 'manager');
    pmSignIn();

    $this->getJson('/api/v1/portal/me')->assertOk()->assertExactJson([
        'address' => 'anna@example.org',
        'domain' => 'example.org',
        'domain_unicode' => 'example.org',
        'quota_bytes' => 2147483648,
        'used_bytes' => 1048576,
        'self_service' => ['forwarding' => true, 'aliases' => false, 'external_forwarding' => false],
        'managers' => [['name' => 'Maija Manager', 'email' => 'maija@console.test']],
        'support_contact' => null,
        'client_settings' => [
            'imap_host' => 'mail.example.org',
            'imap_port' => 993,
            'smtp_host' => 'mail.example.org',
            'smtp_port' => 587,
            'webmail_url' => 'https://webmail.example.org/',
        ],
    ]);
});

it('reads the default quota, unknown usage and an IDN domain', function () {
    pmSignIn('eero@xn--exmple-cua.fi');

    $this->getJson('/api/v1/portal/me')->assertOk()
        ->assertJsonPath('address', 'eero@xn--exmple-cua.fi')
        ->assertJsonPath('domain', 'xn--exmple-cua.fi')
        ->assertJsonPath('domain_unicode', 'exämple.fi')
        ->assertJsonPath('quota_bytes', 5368709120)
        ->assertJsonPath('used_bytes', null);
});

it('reads the switches from the domain settings row while it is the domain\'s own', function () {
    DomainSetting::query()->forceCreate([
        'domain_id' => 10, 'domain_name' => 'example.org',
        'allow_user_forwarding' => false, 'allow_user_aliases' => true, 'allow_external_forwarding' => true,
    ]);
    pmSignIn();

    $this->getJson('/api/v1/portal/me')->assertOk()
        ->assertJsonPath('self_service', ['forwarding' => false, 'aliases' => true, 'external_forwarding' => true]);

    // A row left by a domain renamed outside EmailSquire is not this domain's: the defaults apply.
    DomainSetting::query()->whereKey(10)->update(['domain_name' => 'old-name.org']);
    $this->getJson('/api/v1/portal/me')->assertOk()
        ->assertJsonPath('self_service', ['forwarding' => true, 'aliases' => false, 'external_forwarding' => false]);
});

it('lists only the domain\'s active managers, by name, never admins or viewers', function () {
    pmGrant('zed@console.test', '', 'manager');
    pmGrant('bea@console.test', 'Bea', 'manager');
    pmGrant('viewer@console.test', 'Vera Viewer', 'viewer');
    pmGrant('gone@console.test', 'Gone Manager', 'manager', ['status' => 'deactivated']);
    pmGrant('root@console.test', 'Root Admin', 'manager', ['is_server_admin' => true]);
    pmGrant('stale@console.test', 'Stale Grant', 'manager', domainName: 'renamed.org');
    pmGrant('other@console.test', 'Other Domain', 'manager', domainName: 'xn--exmple-cua.fi', domainId: 11);
    pmSignIn();

    $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonPath('managers', [
        ['name' => 'Bea', 'email' => 'bea@console.test'],
        ['name' => 'zed@console.test', 'email' => 'zed@console.test'],
    ]);
});

it('gives the support contact as plain text of at most 200 characters, or null', function () {
    pmSignIn();

    config(['emailsquire.support_contact' => '  IT desk, room 4: 040 123 4567  ']);
    $this->getJson('/api/v1/portal/me')->assertJsonPath('support_contact', 'IT desk, room 4: 040 123 4567');

    config(['emailsquire.support_contact' => str_repeat('ä', 250)]);
    $this->getJson('/api/v1/portal/me')->assertJsonPath('support_contact', str_repeat('ä', 200));

    config(['emailsquire.support_contact' => "Call\nus\x07now"]);
    $this->getJson('/api/v1/portal/me')->assertJsonPath('support_contact', 'Call us now');

    // Format characters (a right-to-left override, a zero-width space) could reorder or hide the text.
    config(['emailsquire.support_contact' => "IT desk\u{202E} 040\u{200B} 4567"]);
    $this->getJson('/api/v1/portal/me')->assertJsonPath('support_contact', 'IT desk 040 4567');

    config(['emailsquire.support_contact' => '   ']);
    $this->getJson('/api/v1/portal/me')->assertJsonPath('support_contact', null);
});

it('gives the webmail link only when it is an http or https URL', function (string $configured, ?string $expected) {
    config(['emailsquire.webmail_url' => $configured]);
    pmSignIn();

    $this->getJson('/api/v1/portal/me')->assertJsonPath('client_settings.webmail_url', $expected);
})->with([
    'https' => ['https://webmail.example.org/', 'https://webmail.example.org/'],
    'empty' => ['', null],
    'javascript' => ['javascript:alert(1)', null],
    'bare name' => ['webmail', null],
]);

it('answers 401 to a console session and to no session', function () {
    $this->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');

    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
    $this->getJson('/api/v1/portal/me')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});

it('reads the switches by domain id too, with the defaults for a domain that is gone', function () {
    DomainSetting::query()->forceCreate(['domain_id' => 10, 'domain_name' => 'example.org', 'allow_user_aliases' => true]);

    expect(SelfService::for(10))->toBe(['forwarding' => true, 'aliases' => true, 'external_forwarding' => false])
        ->and(SelfService::for(11))->toBe(SelfService::DEFAULTS)
        ->and(SelfService::for(999))->toBe(SelfService::DEFAULTS);
});

it('carries no console capabilities: the portal answer is unchanged by the schema extension', function () {
    pmSignIn();

    $this->getJson('/api/v1/portal/me')->assertOk()->assertJsonMissingPath('capabilities');
});
