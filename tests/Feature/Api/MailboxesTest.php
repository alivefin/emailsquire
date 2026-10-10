<?php

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\AddressClaims;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use App\MailServer\Repositories\MailboxRepository;
use App\MailServer\SourceLock;
use App\Models\AuditEntry;
use App\Models\DisabledMailbox;
use App\Models\HelperTask;
use App\Models\MailboxSetting;
use App\Models\User;
use App\Rules\MailboxPassword;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
    $this->guideTable = fn (string $t) => DB::table(GuideSchema::table($t));
});

$validBody = fn (array $over = []) => $over + [
    'local' => 'Anna.Smith', 'display_name' => 'Anna Smith', 'password' => 'correct-horse-9', 'quota_bytes' => 2147483648,
];

it('lists the domain\'s mailboxes 10 per page sorted by address, with display names, status, usage and forwarding counts', function () {
    foreach (range(1, 11) as $i) {
        ($this->guideTable)('virtual_users')->insert(['domain_id' => 10, 'email' => sprintf('u%02d@example.org', $i), 'password' => 'x']);
    }
    ($this->guideTable)('virtual_aliases')->insert([
        ['domain_id' => 10, 'source' => 'jack@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'zed@example.org', 'destination' => 'john@example.org'],
    ]);
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10, 'display_name' => 'John Doe']);
    DB::table('mailbox_usage')->insert(['email' => 'john@example.org', 'used_kib' => 10, 'limit_kib' => 5242880, 'checked_at' => '2026-10-10 12:00:00']);
    ($this->guideTable)('virtual_domains')->insert(['id' => 11, 'name' => 'other.example']);
    ($this->guideTable)('virtual_users')->insert(['domain_id' => 11, 'email' => 'a@other.example', 'password' => 'x']);

    $page1 = $this->getJson('/api/v1/domains/example.org/mailboxes')->assertOk()
        ->assertJsonCount(10, 'data')
        ->assertJsonPath('meta', ['page' => 1, 'per_page' => 10, 'total' => 13, 'last_page' => 2]);
    expect(collect($page1->json('data'))->pluck('address')->first())->toBe('john@example.org');

    $this->getJson('/api/v1/domains/example.org/mailboxes?page=2')->assertOk()->assertJsonCount(3, 'data');
    $this->getJson('/api/v1/domains/example.org/mailboxes?sort=desc&limit=200')->assertOk()
        ->assertJsonPath('data.0.address', 'u11@example.org');

    $john = collect($this->getJson('/api/v1/domains/example.org/mailboxes?limit=200')->json('data'))->firstWhere('local', 'john');
    expect($john)->toBe([
        'local' => 'john', 'address' => 'john@example.org', 'display_name' => 'John Doe',
        'quota_bytes' => 5368709120, 'quota_is_default' => true, 'status' => 'active',
        'used_bytes' => 10240, 'usage_checked_at' => '2026-10-10T12:00:00+00:00', 'forwarding_count' => 2,
    ]);

    $mary = collect($this->getJson('/api/v1/domains/example.org/mailboxes?limit=200')->json('data'))->firstWhere('local', 'mary');
    expect($mary['used_bytes'])->toBeNull()->and($mary['usage_checked_at'])->toBeNull()->and($mary['display_name'])->toBeNull();

    $this->getJson('/api/v1/domains/example.org/mailboxes?q=John+Doe')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/domains/example.org/mailboxes?q=mar')->assertJsonPath('data.0.address', 'mary@example.org');
    $this->getJson('/api/v1/domains/example.org/mailboxes?q=%25')->assertJsonPath('meta.total', 0);
});

it('the status filter works', function () {
    DisabledMailbox::create(['mailbox_email' => 'mary@example.org', 'domain_id' => 10, 'saved_password' => 'x', 'disabled_at' => now()]);
    // john has the 5 GiB default (5242880 KiB): 81 % used.
    DB::table('mailbox_usage')->insert(['email' => 'john@example.org', 'used_kib' => (int) (5242880 * 0.81), 'limit_kib' => 5242880, 'checked_at' => now()]);
    DB::table('mailbox_usage')->insert(['email' => 'mary@example.org', 'used_kib' => (int) (5242880 * 0.5), 'limit_kib' => 5242880, 'checked_at' => now()]);

    $get = fn (string $s) => collect($this->getJson("/api/v1/domains/example.org/mailboxes?status={$s}")->assertOk()->json('data'))->pluck('local')->all();

    expect($get('all'))->toBe(['john', 'mary'])
        ->and($get('disabled'))->toBe(['mary'])
        ->and($get('active'))->toBe(['john'])
        ->and($get('full'))->toBe(['john']);
    $this->getJson('/api/v1/domains/example.org/mailboxes?status=disabled')->assertJsonPath('data.0.status', 'disabled');
});

it('a mailbox from another domain is 404 under this domain\'s URL', function () {
    ($this->guideTable)('virtual_domains')->insert(['id' => 11, 'name' => 'other.example']);
    ($this->guideTable)('virtual_users')->insert(['domain_id' => 11, 'email' => 'zoe@other.example', 'password' => 'x']);

    $this->getJson('/api/v1/domains/other.example/mailboxes/zoe')->assertOk()->assertJsonPath('address', 'zoe@other.example');
    $this->getJson('/api/v1/domains/example.org/mailboxes/zoe')->assertNotFound();
    $this->getJson('/api/v1/domains/example.org/mailboxes/JOHN')->assertOk()->assertJsonPath('address', 'john@example.org');
    $this->patchJson('/api/v1/domains/example.org/mailboxes/zoe', ['quota_bytes' => 2097152])->assertNotFound();
});

it('creates a mailbox with a BLF-CRYPT hash, quota bytes and display name', function () use ($validBody) {
    $response = $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody())->assertCreated()
        ->assertJsonPath('address', 'anna.smith@example.org')
        ->assertJsonPath('local', 'anna.smith')
        ->assertJsonPath('display_name', 'Anna Smith')
        ->assertJsonPath('quota_bytes', 2147483648)
        ->assertJsonPath('quota_is_default', false)
        ->assertJsonPath('status', 'active')
        ->assertJsonPath('used_bytes', null)
        ->assertJsonPath('forwarding_count', 0);
    expect($response->json())->not->toHaveKey('password');

    $row = ($this->guideTable)('virtual_users')->where('email', 'anna.smith@example.org')->first();
    expect($row->domain_id)->toBe(10)
        ->and($row->quota)->toBe(2147483648)
        ->and($row->password)->toStartWith('{BLF-CRYPT}$2y$12$')
        ->and(password_verify('correct-horse-9', substr($row->password, 11)))->toBeTrue();
    expect(MailboxSetting::find('anna.smith@example.org')->display_name)->toBe('Anna Smith');

    $audit = AuditEntry::sole();
    expect($audit->action)->toBe('mailbox.create')
        ->and($audit->object_key)->toBe('anna.smith@example.org')
        ->and($audit->domain_name)->toBe('example.org')
        ->and($audit->changes)->toHaveKey('quota_bytes', 2147483648)
        ->and(json_encode($audit->changes))->not->toContain('correct-horse');
});

it('creates a mailbox without a display name', function () use ($validBody) {
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['display_name' => null, 'local' => 'bob']))
        ->assertCreated()->assertJsonPath('display_name', null);
    expect(MailboxSetting::count())->toBe(0);
});

it('refuses a weak, short or 73-byte password with 422 errors.password', function (string $password, string $message) use ($validBody) {
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['password' => $password]))->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.password.0', $message);
    expect(($this->guideTable)('virtual_users')->count())->toBe(2);
})->with([
    'weak' => ['aaaaaaaaaaaaaaaa', 'Too weak — use 12+ characters with a number or symbol.'],
    'short' => ['Ab1!xyz', 'Too weak — use 12+ characters with a number or symbol.'],
    '73 bytes' => [str_repeat('aB3-', 18).'x', 'Use at most 72 bytes.'],
    '73 bytes multibyte' => [str_repeat('é', 35).'aB3-xx', 'Use at most 72 bytes.'],
]);

it('accepts a 72-byte password', function () use ($validBody) {
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['password' => str_repeat('aB3-', 18)]))->assertCreated();
});

it('refuses quota 0, below 1 MiB or above 1 TiB', function (mixed $quota) use ($validBody) {
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['quota_bytes' => $quota]))->assertStatus(422)
        ->assertJsonStructure(['errors' => ['quota_bytes']]);
})->with([0, 1048575, 1099511627777, 'big', null]);

it('accepts the quota limits', function (int $quota) use ($validBody) {
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['quota_bytes' => $quota]))->assertCreated();
})->with([1048576, 1099511627776]);

it('refuses a malformed local part with 422 errors.local', function (string $local) use ($validBody) {
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['local' => $local]))->assertStatus(422)
        ->assertJsonStructure(['errors' => ['local']]);
})->with(['a+b', 'a..b', 'ends.', 'jörg', 'a b', str_repeat('a', 90)]);

it('refuses a blocked, existing or forwarding address with the claim codes', function () use ($validBody) {
    DB::table('blocked_addresses')->insert(['address' => 'postmaster@example.org', 'reason' => 'reserved', 'created_at' => now()]);

    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['local' => 'postmaster']))->assertStatus(422)
        ->assertJsonPath('code', 'blocked')->assertJsonStructure(['errors' => ['local']]);
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['local' => 'JOHN']))->assertStatus(422)
        ->assertJsonPath('code', 'exists_mailbox')->assertJsonPath('errors.local.0', 'john@example.org already exists.');
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['local' => 'jack']))->assertStatus(422)
        ->assertJsonPath('code', 'exists_forwarding');

    expect(AuditEntry::count())->toBe(0)->and(($this->guideTable)('virtual_users')->count())->toBe(2);
});

it('returns 409 archiving while an archive task is pending', function () use ($validBody) {
    HelperTask::create(['type' => 'maildir.archive', 'status' => 'pending', 'address' => 'gone@example.org', 'domain_name' => 'example.org', 'run_after' => now()]);

    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['local' => 'gone']))->assertStatus(409)
        ->assertJsonPath('code', 'archiving');
    expect(($this->guideTable)('virtual_users')->where('email', 'gone@example.org')->exists())->toBeFalse();
});

it('releases the source lock after a refused create', function () use ($validBody) {
    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody(['local' => 'john']))->assertStatus(422);
    $name = SourceLock::name('john@example.org');

    expect((int) DB::selectOne('select is_free_lock(?) as f', [$name])->f)->toBe(1);
});

it('refuses with 409 busy when another session holds the address lock', function () use ($validBody) {
    $name = SourceLock::name('anna.smith@example.org');
    $other = DB::build(config('database.connections.'.config('database.default')));
    $other->selectOne('select get_lock(?, 0) as l', [$name]);
    config(['emailsquire.source_lock_timeout' => 0]);

    $this->postJson('/api/v1/domains/example.org/mailboxes', $validBody())->assertStatus(409)
        ->assertJsonPath('code', 'busy')
        ->assertJsonPath('detail', 'Someone else is changing this address. Try again.');
    $other->selectOne('select release_lock(?)', [$name]);
});

it('PATCH changes the quota and display name and audits mailbox.update with old and new quota', function () {
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10, 'display_name' => 'Old Name']);

    $this->patchJson('/api/v1/domains/example.org/mailboxes/john', ['quota_bytes' => 3145728, 'display_name' => 'John D'])->assertOk()
        ->assertJsonPath('quota_bytes', 3145728)->assertJsonPath('quota_is_default', false)->assertJsonPath('display_name', 'John D');

    expect(($this->guideTable)('virtual_users')->where('email', 'john@example.org')->value('quota'))->toBe(3145728)
        ->and(MailboxSetting::find('john@example.org')->display_name)->toBe('John D');
    $audit = AuditEntry::sole();
    expect($audit->action)->toBe('mailbox.update')->and($audit->object_key)->toBe('john@example.org')
        ->and($audit->changes['quota_bytes'])->toBe(['old' => 5368709120, 'new' => 3145728]);
});

it('PATCH can set the display name alone, clear it, and create the settings row', function () {
    $this->patchJson('/api/v1/domains/example.org/mailboxes/mary', ['display_name' => 'Mary'])->assertOk()->assertJsonPath('display_name', 'Mary');
    expect(MailboxSetting::find('mary@example.org')->domain_id)->toBe(10);
    $this->patchJson('/api/v1/domains/example.org/mailboxes/mary', ['display_name' => null])->assertOk()->assertJsonPath('display_name', null);
    expect(($this->guideTable)('virtual_users')->where('email', 'mary@example.org')->value('quota'))->toBe(0);
});

it('PATCH that changes nothing writes nothing and audits nothing', function (array $body) {
    ($this->guideTable)('virtual_users')->where('email', 'john@example.org')->update(['quota' => 3145728]);
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10, 'display_name' => 'John']);

    $this->patchJson('/api/v1/domains/example.org/mailboxes/john', $body)->assertOk()
        ->assertJsonPath('quota_bytes', 3145728)->assertJsonPath('display_name', 'John');
    $this->patchJson('/api/v1/domains/example.org/mailboxes/mary', ['display_name' => null])->assertOk();

    expect(AuditEntry::count())->toBe(0)
        ->and(MailboxSetting::pluck('mailbox_email')->all())->toBe(['john@example.org']);
})->with([
    'empty body' => [[]],
    'same values' => [['quota_bytes' => 3145728, 'display_name' => 'John']],
]);

it('PATCH audits only the fields that change', function () {
    MailboxSetting::create(['mailbox_email' => 'john@example.org', 'domain_id' => 10, 'display_name' => 'John']);

    $this->patchJson('/api/v1/domains/example.org/mailboxes/john', ['quota_bytes' => 3145728, 'display_name' => 'John'])->assertOk();

    expect(AuditEntry::sole()->changes)->toBe(['quota_bytes' => ['old' => 5368709120, 'new' => 3145728]]);
});

it('PATCH answers 409 busy while another session holds the address lock', function () {
    $name = SourceLock::name('john@example.org');
    $other = DB::build(config('database.connections.'.config('database.default')));
    try {
        $other->selectOne('select get_lock(?, 0) as l', [$name]);
        config(['emailsquire.source_lock_timeout' => 0]);

        $this->patchJson('/api/v1/domains/example.org/mailboxes/john', ['quota_bytes' => 3145728])
            ->assertStatus(409)->assertJsonPath('code', 'busy');
    } finally {
        $other->selectOne('select release_lock(?)', [$name]);
        $other->disconnect();
    }
});

it('PATCH refuses a quota outside the limits', function (mixed $quota) {
    $this->patchJson('/api/v1/domains/example.org/mailboxes/john', ['quota_bytes' => $quota])->assertStatus(422)
        ->assertJsonStructure(['errors' => ['quota_bytes']]);
})->with([0, 5, 1099511627777]);

it('password reset returns a 16-character password once with Cache-Control no-store', function () {
    $log = tempnam(sys_get_temp_dir(), 'es-log');
    config(['logging.channels.single.path' => $log, 'logging.default' => 'single']);
    $kicked = [];
    Http::fake(function (Request $request) use (&$kicked) {
        $call = $request->data()[0];
        $kicked[] = $call[1]['mask'];

        return Http::response([['doveadmResponse', [], $call[2]]]);
    });

    $response = $this->postJson('/api/v1/domains/example.org/mailboxes/john/password', ['generate' => true])->assertOk();
    $password = $response->json('password');

    expect($password)->toBeString()->toHaveLength(16)
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and(array_keys($response->json()))->toBe(['password']);
    $stored = ($this->guideTable)('virtual_users')->where('email', 'john@example.org')->value('password');
    expect($stored)->toStartWith('{BLF-CRYPT}$2y$12$')->and(password_verify($password, substr($stored, 11)))->toBeTrue();

    $audit = AuditEntry::sole();
    expect($audit->action)->toBe('mailbox.password_reset')->and($audit->object_key)->toBe('john@example.org')
        ->and(json_encode($audit->toArray()))->not->toContain($password);
    expect($kicked)->toBe([['john@example.org']]);
    expect((string) file_get_contents($log))->not->toContain($password);
    unlink($log);
});

it('password reset needs generate: true', function () {
    Http::fake();
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/password', [])->assertStatus(422)->assertJsonStructure(['errors' => ['generate']]);
    $this->postJson('/api/v1/domains/example.org/mailboxes/john/password', ['generate' => false])->assertStatus(422);
});

it('password reset on a disabled mailbox changes the saved hash, not the live one', function () {
    Http::fake();
    $live = ($this->guideTable)('virtual_users')->where('email', 'mary@example.org')->value('password');
    DisabledMailbox::create(['mailbox_email' => 'mary@example.org', 'domain_id' => 10, 'saved_password' => 'old-saved', 'disabled_at' => now()]);

    $password = $this->postJson('/api/v1/domains/example.org/mailboxes/mary/password', ['generate' => true])->assertOk()->json('password');

    expect(($this->guideTable)('virtual_users')->where('email', 'mary@example.org')->value('password'))->toBe($live);
    $saved = DB::table('disabled_mailboxes')->where('mailbox_email', 'mary@example.org')->value('saved_password');
    expect(password_verify($password, substr($saved, 11)))->toBeTrue();
});

it('a mailbox stored with quota 0 reports the 5 GiB default with quota_is_default true', function () {
    $this->getJson('/api/v1/domains/example.org/mailboxes/john')->assertOk()
        ->assertJsonPath('quota_bytes', 5368709120)->assertJsonPath('quota_is_default', true);
});

it('keeps the plaintext password out of stack traces', function () {
    ini_set('zend.exception_ignore_args', '0');
    $secret = 'Tr0ub4dor&3-horse';
    $domain = Domain::query()->where('name', 'example.org')->firstOrFail();
    $address = app(AddressNormalizer::class)->addressIn('trace', app(AddressNormalizer::class)->domain('example.org'));
    $this->app->bind(AddressClaims::class, fn () => new class extends AddressClaims
    {
        public function __construct() {}

        public function assertCanCreateMailbox(EmailAddress $a): void
        {
            throw new RuntimeException('lost connection');
        }
    });

    $traces = [];
    try {
        app(MailboxRepository::class)->create($domain, $address, $secret, 1048576, null);
    } catch (RuntimeException $e) {
        $traces[] = $e->getTraceAsString();
    }
    try {
        (new MailboxPassword)->validate('password', 'aaaaaaaaaaaaaaaaaaaa', fn () => throw new RuntimeException('x'));
    } catch (RuntimeException $e) {
        $traces[] = $e->getTraceAsString();
    }

    expect($traces)->toHaveCount(2);
    expect($traces[0])->not->toContain('Tr0ub4')->toContain('SensitiveParameterValue');
    expect($traces[1])->not->toContain('aaaaaaaaaaaa')->toContain('SensitiveParameterValue');
});

it('binds the mailbox local strictly: no accent folding, no plus, case-insensitive', function () {
    $this->getJson('/api/v1/domains/example.org/mailboxes/jöhn')->assertNotFound();
    $this->getJson('/api/v1/domains/example.org/mailboxes/john%2Bx')->assertNotFound();
    $this->getJson('/api/v1/domains/example.org/mailboxes/JOHN')->assertOk()->assertJsonPath('address', 'john@example.org');
});

it('still resolves a legacy mixed-case stored address by its lowercase local', function () {
    GuideFixture::load('trixie', 'anomalies');
    $this->getJson('/api/v1/domains/example.org/mailboxes/mixed')->assertOk()->assertJsonPath('address', 'Mixed@example.org');
});
