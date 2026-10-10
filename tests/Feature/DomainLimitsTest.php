<?php

use App\MailServer\GuideSchema;
use App\MailServer\QuotaFormat;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Support\GuideFixture;

// Per-domain limits (plan 2c, Task 2): max_mailboxes and max_quota_bytes_total in domain_settings,
// enforced inside the repository transaction with the domain row FOR UPDATE, for every role.

const LIM_GIB = 1073741824;
const LIM_MIB = 1048576;

beforeEach(function () {
    config(['emailsquire.require_two_factor' => 'off']);
    GuideFixture::load('trixie');
    // lim.org: one mailbox on the 5 GiB default (quota 0).
    limGuide('virtual_domains')->insert(['id' => 50, 'name' => 'lim.org']);
    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'one@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => 0]);
    $this->admin = User::factory()->create(['is_server_admin' => true, 'password_set_at' => now()]);
    $this->actingAs($this->admin);
});

function limGuide(string $table): Builder
{
    return DB::table(GuideSchema::table($table));
}

/** @param  array{max_mailboxes?: int|null, max_quota_bytes_total?: int|null, domain_name?: string}  $values */
function limSet(array $values): void
{
    DB::table('domain_settings')->insert($values + ['domain_id' => 50, 'domain_name' => 'lim.org']);
}

function limCreate(string $local, int $quota): TestResponse
{
    return test()->postJson('/api/v1/domains/lim.org/mailboxes', ['local' => $local, 'password' => 'correct-horse-9', 'quota_bytes' => $quota]);
}

function limGrant(string $role): User
{
    $user = User::factory()->create(['password_set_at' => now()]);
    DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => 50, 'domain_name' => 'lim.org', 'role' => $role]);

    return $user;
}

function limQuota(string $email): int
{
    return (int) limGuide('virtual_users')->where('email', $email)->value('quota');
}

it('allows a create up to max_mailboxes and refuses the next with 422 domain_limit naming the limit', function () {
    limSet(['max_mailboxes' => 2]);

    limCreate('two', 2 * LIM_GIB)->assertCreated();

    $refused = limCreate('three', 2 * LIM_GIB)->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'domain_limit');
    expect($refused->json('detail'))->toBe('This domain can have at most 2 mailboxes.')
        ->and(limGuide('virtual_users')->where('email', 'three@lim.org')->exists())->toBeFalse()
        ->and(AuditEntry::query()->where('object_key', 'three@lim.org')->exists())->toBeFalse();
});

it('counts a default-quota mailbox as quota_default_bytes against max_quota_bytes_total', function () {
    limSet(['max_quota_bytes_total' => 10 * LIM_GIB]);

    limCreate('two', 5 * LIM_GIB)->assertCreated();

    limCreate('three', LIM_MIB)->assertStatus(422)
        ->assertJsonPath('code', 'domain_limit')
        ->assertJsonPath('detail', 'The mailboxes of this domain can have at most 10 GB of storage in total.');
    expect(limGuide('virtual_users')->where('email', 'three@lim.org')->exists())->toBeFalse();
});

it('refuses raising a quota past the total and always allows lowering one', function () {
    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'two@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => 5 * LIM_GIB]);
    limSet(['max_quota_bytes_total' => 10 * LIM_GIB]);

    $this->patchJson('/api/v1/domains/lim.org/mailboxes/two', ['quota_bytes' => 6 * LIM_GIB])->assertStatus(422)
        ->assertJsonPath('code', 'domain_limit');
    expect(limQuota('two@lim.org'))->toBe(5 * LIM_GIB);

    // The default spelled out is no change in allocation.
    $this->patchJson('/api/v1/domains/lim.org/mailboxes/one', ['quota_bytes' => 5 * LIM_GIB])->assertOk();

    // A limit below what is allocated: lowering still works, raising does not.
    DB::table('domain_settings')->where('domain_id', 50)->update(['max_quota_bytes_total' => LIM_GIB]);
    $this->patchJson('/api/v1/domains/lim.org/mailboxes/two', ['quota_bytes' => 4 * LIM_GIB])->assertOk();
    expect(limQuota('two@lim.org'))->toBe(4 * LIM_GIB);
    $this->patchJson('/api/v1/domains/lim.org/mailboxes/two', ['quota_bytes' => 4 * LIM_GIB + LIM_MIB])->assertStatus(422);
});

it('refuses a bulk quota change that would exceed the total and changes nothing', function () {
    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'two@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => 5 * LIM_GIB]);
    limSet(['max_quota_bytes_total' => 12 * LIM_GIB]);
    $audits = AuditEntry::query()->count();

    $this->postJson('/api/v1/domains/lim.org/mailboxes/bulk', ['locals' => ['one', 'two'], 'action' => 'quota', 'quota_bytes' => 7 * LIM_GIB])
        ->assertStatus(422)->assertJsonPath('code', 'domain_limit');

    expect(limQuota('one@lim.org'))->toBe(0)
        ->and(limQuota('two@lim.org'))->toBe(5 * LIM_GIB)
        ->and(AuditEntry::query()->count())->toBe($audits);

    // Exactly at the limit is fine.
    $this->postJson('/api/v1/domains/lim.org/mailboxes/bulk', ['locals' => ['one', 'two'], 'action' => 'quota', 'quota_bytes' => 6 * LIM_GIB])
        ->assertOk()->assertJsonPath('changed', 2);
});

it('applies the limits to server admins and managers alike', function () {
    limSet(['max_mailboxes' => 1]);
    expect($this->admin->is_server_admin)->toBeTrue();

    limCreate('two', LIM_GIB)->assertStatus(422)->assertJsonPath('code', 'domain_limit')
        ->assertJsonPath('detail', 'This domain can have at most 1 mailbox.');

    $this->actingAs(limGrant('manager'));
    limCreate('two', LIM_GIB)->assertStatus(422)->assertJsonPath('code', 'domain_limit');
});

it('takes the domain row FOR UPDATE before counting, on create, quota change and bulk quota', function (string $path) {
    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'two@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => LIM_GIB]);
    limSet(['max_mailboxes' => 100, 'max_quota_bytes_total' => 100 * LIM_GIB]);

    DB::enableQueryLog();
    match ($path) {
        'create' => limCreate('three', LIM_GIB)->assertCreated(),
        'quota' => $this->patchJson('/api/v1/domains/lim.org/mailboxes/two', ['quota_bytes' => 2 * LIM_GIB])->assertOk(),
        'bulk' => $this->postJson('/api/v1/domains/lim.org/mailboxes/bulk', ['locals' => ['one', 'two'], 'action' => 'quota', 'quota_bytes' => 6 * LIM_GIB])->assertOk(),
    };
    $sql = array_map(fn (array $q) => strtolower($q['query']), DB::getQueryLog());
    DB::disableQueryLog();

    $domainLock = null;
    $count = null;
    foreach ($sql as $i => $q) {
        if ($domainLock === null && str_contains($q, 'virtual_domains') && str_contains($q, 'for update')) {
            $domainLock = $i;
        }
        if ($count === null && str_contains($q, 'virtual_users') && str_contains($q, 'count(')) {
            $count = $i;
        }
    }

    expect($domainLock)->not->toBeNull(implode("\n", $sql))
        ->and($count)->not->toBeNull(implode("\n", $sql))
        ->and($domainLock)->toBeLessThan($count)
        // No shared lock on the domain first: two writers holding it shared could not both upgrade.
        ->and(array_filter($sql, fn (string $q) => str_contains($q, 'virtual_domains') && str_contains($q, 'lock in share mode')))->toBe([]);
})->with(['create', 'quota', 'bulk']);

it('shows the limits and usage to a viewer and refuses a manager\'s change with 403', function () {
    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'two@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => 2 * LIM_GIB]);
    limSet(['max_mailboxes' => 5]);

    $this->actingAs(limGrant('viewer'));
    $this->getJson('/api/v1/domains/lim.org/settings')->assertOk()->assertExactJson([
        'max_mailboxes' => 5,
        'max_quota_bytes_total' => null,
        'usage' => ['mailboxes' => 2, 'quota_bytes_allocated' => 7 * LIM_GIB],
    ]);

    $this->actingAs(limGrant('manager'));
    $this->getJson('/api/v1/domains/lim.org/settings')->assertOk();
    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => 10])->assertForbidden()->assertJsonPath('code', 'forbidden');
    expect(DB::table('domain_settings')->where('domain_id', 50)->value('max_mailboxes'))->toBe(5);
});

it('reports no limits for a domain without a settings row, or with one left from another name', function () {
    $this->getJson('/api/v1/domains/lim.org/settings')->assertOk()->assertExactJson([
        'max_mailboxes' => null,
        'max_quota_bytes_total' => null,
        'usage' => ['mailboxes' => 1, 'quota_bytes_allocated' => 5 * LIM_GIB],
    ]);

    // A row whose domain was renamed outside EmailSquire is not this domain's (doctor: app.orphans).
    limSet(['max_mailboxes' => 1, 'domain_name' => 'old.org']);
    $this->getJson('/api/v1/domains/lim.org/settings')->assertJsonPath('max_mailboxes', null);
    limCreate('two', LIM_GIB)->assertCreated();
});

it('upserts the settings, audits only the changed fields and returns the GET body', function () {
    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => 5])->assertOk()->assertExactJson([
        'max_mailboxes' => 5,
        'max_quota_bytes_total' => null,
        'usage' => ['mailboxes' => 1, 'quota_bytes_allocated' => 5 * LIM_GIB],
    ]);
    expect(DB::table('domain_settings')->where('domain_id', 50)->first(['domain_name', 'max_mailboxes']))
        ->toEqual((object) ['domain_name' => 'lim.org', 'max_mailboxes' => 5]);

    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => 5, 'max_quota_bytes_total' => 20 * LIM_GIB])->assertOk()
        ->assertJsonPath('max_quota_bytes_total', 20 * LIM_GIB);
    // Nothing changed: no audit row.
    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => 5])->assertOk();
    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => null])->assertOk()->assertJsonPath('max_mailboxes', null);

    $rows = AuditEntry::query()->where('action', 'domain.limits_changed')->orderBy('id')->get();
    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('changes')->all())->toBe([
            ['max_mailboxes' => [null, 5]],
            ['max_quota_bytes_total' => [null, 20 * LIM_GIB]],
            ['max_mailboxes' => [5, null]],
        ])
        ->and($rows->pluck('object_type')->unique()->all())->toBe(['domain'])
        ->and($rows->pluck('object_key')->unique()->all())->toBe(['lim.org'])
        ->and($rows->pluck('domain_name')->unique()->all())->toBe(['lim.org'])
        ->and($rows->pluck('actor_id')->unique()->all())->toBe([(string) $this->admin->id]);
});

it('accepts a limit below current use: existing mailboxes stay, new ones are refused', function () {
    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => 1, 'max_quota_bytes_total' => LIM_MIB])->assertOk()
        ->assertJsonPath('usage.mailboxes', 1);

    expect(limGuide('virtual_users')->where('email', 'one@lim.org')->exists())->toBeTrue();
    limCreate('two', LIM_MIB)->assertStatus(422)->assertJsonPath('code', 'domain_limit');
});

it('rejects zero, negative, out-of-range and non-integer limits with 422 validation_failed', function (array $body) {
    $this->patchJson('/api/v1/domains/lim.org/settings', $body)->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed');
    expect(DB::table('domain_settings')->count())->toBe(0);
})->with([
    'zero mailboxes' => [['max_mailboxes' => 0]],
    'negative mailboxes' => [['max_mailboxes' => -1]],
    'too many mailboxes' => [['max_mailboxes' => 100001]],
    'fractional mailboxes' => [['max_mailboxes' => 1.5]],
    'zero quota' => [['max_quota_bytes_total' => 0]],
    'negative quota' => [['max_quota_bytes_total' => -LIM_GIB]],
    'quota below 1 MiB' => [['max_quota_bytes_total' => LIM_MIB - 1]],
    'quota above 1 PiB' => [['max_quota_bytes_total' => 1125899906842624 + 1]],
    'text quota' => [['max_quota_bytes_total' => 'lots']],
]);

it('accepts the range ends', function () {
    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => 100000, 'max_quota_bytes_total' => 1125899906842624])->assertOk();
    $this->patchJson('/api/v1/domains/lim.org/settings', ['max_mailboxes' => 1, 'max_quota_bytes_total' => LIM_MIB])->assertOk();
});

it('answers the limit in Finnish with Accept-Language: fi', function () {
    limSet(['max_mailboxes' => 1]);
    $this->withHeader('Accept-Language', 'fi');

    limCreate('two', LIM_GIB)->assertStatus(422)
        ->assertJsonPath('detail', 'Tällä verkkotunnuksella voi olla enintään 1 postilaatikko.');

    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'two@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => LIM_GIB]);
    DB::table('domain_settings')->where('domain_id', 50)->update(['max_mailboxes' => 2]);
    limCreate('three', LIM_GIB)->assertStatus(422)
        ->assertJsonPath('detail', 'Tällä verkkotunnuksella voi olla enintään 2 postilaatikkoa.');

    // 6 GiB allocated; 5 GiB more passes 10.5 GiB.
    DB::table('domain_settings')->where('domain_id', 50)->update(['max_mailboxes' => null, 'max_quota_bytes_total' => (int) (10.5 * LIM_GIB)]);
    limCreate('three', 5 * LIM_GIB)->assertStatus(422)
        ->assertJsonPath('detail', 'Tämän verkkotunnuksen postilaatikoille voi varata yhteensä enintään 10,5 Gt tallennustilaa.');
});

it('lists the limits and the allocated quota on each domain', function () {
    limSet(['max_mailboxes' => 3, 'max_quota_bytes_total' => 20 * LIM_GIB]);
    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'two@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => 2 * LIM_GIB]);

    $rows = collect($this->getJson('/api/v1/domains?limit=200')->assertOk()->json('data'))->keyBy('name');

    expect($rows['lim.org'])->toMatchArray(['max_mailboxes' => 3, 'max_quota_bytes_total' => 20 * LIM_GIB, 'quota_bytes_allocated' => 7 * LIM_GIB])
        ->and($rows['example.org'])->toMatchArray(['max_mailboxes' => null, 'max_quota_bytes_total' => null, 'quota_bytes_allocated' => 10 * LIM_GIB]);
});

it('deletes the domain\'s settings row with the domain', function () {
    limSet(['max_mailboxes' => 3]);
    DB::table('domain_settings')->insert(['domain_id' => 10, 'domain_name' => 'example.org', 'max_mailboxes' => 9]);
    Http::fake();
    confirmStepUp();

    $this->deleteJson('/api/v1/domains/lim.org', ['confirm_name' => 'lim.org'])->assertNoContent();

    expect(DB::table('domain_settings')->pluck('domain_id')->all())->toBe([10]);
});

it('never understates a limit: sizes round up to the shown precision', function (int $bytes, string $en, string $fi) {
    app()->setLocale('en');
    expect(QuotaFormat::size($bytes))->toBe($en);
    app()->setLocale('fi');
    expect(QuotaFormat::size($bytes))->toBe($fi);
})->with([
    'exact GiB' => [10 * LIM_GIB, '10 GB', '10 Gt'],
    'exact tenth' => [(int) (10.5 * LIM_GIB), '10.5 GB', '10,5 Gt'],
    '10 GiB and a byte' => [10 * LIM_GIB + 1, '10.1 GB', '10,1 Gt'],
    '1.04 GiB' => [(int) (1.04 * LIM_GIB), '1.1 GB', '1,1 Gt'],
    '9.95 GiB' => [(int) (9.95 * LIM_GIB), '10 GB', '10 Gt'],
    'exact MiB' => [512 * LIM_MIB, '512 MB', '512 Mt'],
    'a MiB and a byte' => [LIM_MIB + 1, '2 MB', '2 Mt'],
]);

it('names the rounded-up limit in the refusal, in English and Finnish', function () {
    limSet(['max_quota_bytes_total' => 10 * LIM_GIB + 1]);
    limCreate('two', 6 * LIM_GIB)->assertStatus(422)
        ->assertJsonPath('detail', 'The mailboxes of this domain can have at most 10.1 GB of storage in total.');

    DB::table('domain_settings')->where('domain_id', 50)->update(['max_quota_bytes_total' => (int) (1.04 * LIM_GIB)]);
    $this->withHeader('Accept-Language', 'fi');
    limCreate('two', LIM_GIB)->assertStatus(422)
        ->assertJsonPath('detail', 'Tämän verkkotunnuksen postilaatikoille voi varata yhteensä enintään 1,1 Gt tallennustilaa.');
});

it('attaches the refusal to the field: local for the mailbox count, quota_bytes for storage', function () {
    limGuide('virtual_users')->insert(['domain_id' => 50, 'email' => 'two@lim.org', 'password' => '{BLF-CRYPT}x', 'quota' => LIM_GIB]);
    limSet(['max_mailboxes' => 2, 'max_quota_bytes_total' => 7 * LIM_GIB]);

    $count = limCreate('three', LIM_MIB)->assertStatus(422);
    expect($count->json())->toBe([
        'type' => 'about:blank',
        'title' => 'Domain limit reached',
        'status' => 422,
        'detail' => 'This domain can have at most 2 mailboxes.',
        'code' => 'domain_limit',
        'errors' => ['local' => ['This domain can have at most 2 mailboxes.']],
    ]);

    DB::table('domain_settings')->where('domain_id', 50)->update(['max_mailboxes' => null]);
    $storage = 'The mailboxes of this domain can have at most 7 GB of storage in total.';
    limCreate('three', 2 * LIM_GIB)->assertStatus(422)
        ->assertJsonPath('errors', ['quota_bytes' => [$storage]]);
    $this->patchJson('/api/v1/domains/lim.org/mailboxes/two', ['quota_bytes' => 3 * LIM_GIB])->assertStatus(422)
        ->assertJsonPath('code', 'domain_limit')
        ->assertJsonPath('errors', ['quota_bytes' => [$storage]]);
    $this->postJson('/api/v1/domains/lim.org/mailboxes/bulk', ['locals' => ['one', 'two'], 'action' => 'quota', 'quota_bytes' => 4 * LIM_GIB])
        ->assertStatus(422)
        ->assertJsonPath('code', 'domain_limit')
        ->assertJsonPath('errors', ['quota_bytes' => [$storage]]);
});
