<?php

use App\Auth\Mailbox\MailboxPrincipal;
use App\Doctor\Support\AliasMaps;
use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;
use Tests\Support\TempTree;

/*
 * Catch-all forwarding (`@domain`, backend plan §6 rule 5): admins and managers set it through the console's
 * forwarding routes with `@` as the local part, only while Postfix's virtual_alias_maps include the guide's
 * email2email map (doctor's postfix.alias_maps). Without that map a catch-all would take the mail of every
 * existing mailbox, so the write is refused with 409 `catch_all_unsupported`.
 */

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->admin = User::factory()->create(['is_server_admin' => true]);
    $this->actingAs($this->admin);
    $this->postfix = TempTree::create();
    $this->url = '/api/v1/domains/example.org/forwarding/@';
    $this->rows = fn (string $source) => DB::table(GuideSchema::table('virtual_aliases'))
        ->where('source', $source)->orderBy('id')->get()
        ->map(fn ($r) => [(int) $r->domain_id, $r->source, $r->destination])->all();
    supportCatchAll($this->postfix, true);
});

afterEach(function () {
    if (is_dir($this->postfix)) {
        chmod($this->postfix, 0700);
        foreach (glob("{$this->postfix}/*") ?: [] as $file) {
            chmod($file, 0600);
        }
        TempTree::remove($this->postfix);
    }
});

/**
 * Writes an installer-style main.cf under $dir and points the config at it: with the email2email map in
 * virtual_alias_maps when $supported, without it otherwise.
 */
function supportCatchAll(string $dir, bool $supported): void
{
    $cf = fn (string $query) => "user = mailserver\npassword = secret\nhosts = 127.0.0.1\ndbname = mailserver\nquery = {$query}\n";
    file_put_contents("{$dir}/mariadb-virtual-alias-maps.cf", $cf("SELECT destination FROM virtual_aliases WHERE source='%s'"));
    file_put_contents("{$dir}/mariadb-email2email.cf", $cf(AliasMaps::EMAIL2EMAIL_QUERY));
    $maps = "mysql:{$dir}/mariadb-virtual-alias-maps.cf".($supported ? ",mysql:{$dir}/mariadb-email2email.cf" : '');
    file_put_contents("{$dir}/main.cf", "virtual_alias_maps = {$maps}\n");
    config()->set('emailsquire.paths.postfix_main_cf', "{$dir}/main.cf");
}

function grantCatchAll(User $user, string $role): void
{
    DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => 10, 'domain_name' => 'example.org', 'role' => $role]);
}

it('sets a catch-all for the domain with targets validated like other forwarding', function () {
    $this->putJson($this->url, ['targets' => ['John@Example.org', 'me+tag@gmail.com', 'john@example.org'], 'keep_copy' => false])
        ->assertCreated()
        ->assertJsonPath('local', '')
        ->assertJsonPath('source', '@example.org')
        ->assertJsonPath('catch_all', true)
        ->assertJsonPath('source_is_mailbox', false)
        ->assertJsonPath('keep_copy', false)
        ->assertJsonPath('targets', [
            ['address' => 'john@example.org', 'kind' => 'mailbox'],
            ['address' => 'me+tag@gmail.com', 'kind' => 'external'],
        ]);

    expect(($this->rows)('@example.org'))->toBe([
        [10, '@example.org', 'john@example.org'],
        [10, '@example.org', 'me+tag@gmail.com'],
    ]);
    $audit = AuditEntry::query()->where('action', 'forwarding.catch_all_set')->sole();
    expect($audit->object_type)->toBe('forwarding')
        ->and($audit->object_key)->toBe('@example.org')
        ->and($audit->domain_name)->toBe('example.org')
        ->and($audit->changes)->toBe(['old' => [], 'new' => ['john@example.org', 'me+tag@gmail.com']])
        ->and(AuditEntry::query()->where('action', 'forwarding.replace')->exists())->toBeFalse();
});

it('replaces the catch-all targets on a second PUT, also with the local part URL-encoded', function () {
    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertCreated();
    $this->putJson('/api/v1/domains/example.org/forwarding/%40', ['targets' => ['mary@example.org']])->assertOk()
        ->assertJsonPath('targets', [['address' => 'mary@example.org', 'kind' => 'mailbox']]);

    expect(($this->rows)('@example.org'))->toBe([[10, '@example.org', 'mary@example.org']]);
    $audit = AuditEntry::query()->where('action', 'forwarding.catch_all_set')->orderByDesc('id')->firstOrFail();
    expect($audit->changes)->toBe(['old' => ['john@example.org'], 'new' => ['mary@example.org']]);
});

it('replaces a legacy mixed-case catch-all row', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 10, 'source' => '@Example.org', 'destination' => 'Old@gmail.com']);

    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertOk();

    expect(DB::table(GuideSchema::table('virtual_aliases'))->whereRaw('lower(source) = ?', ['@example.org'])->pluck('destination')->all())
        ->toBe(['john@example.org']);
});

it('refuses a catch-all with 409 catch_all_unsupported when the alias maps lack email2email', function () {
    supportCatchAll($this->postfix, false);

    $this->putJson($this->url, ['targets' => ['john@example.org']])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'catch_all_unsupported')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'email2email'));

    expect(($this->rows)('@example.org'))->toBe([])
        ->and(AuditEntry::query()->count())->toBe(0);
});

it('refuses a catch-all when main.cf is missing, saying the configuration could not be read', function () {
    config()->set('emailsquire.paths.postfix_main_cf', "{$this->postfix}/nope/main.cf");

    $this->putJson($this->url, ['targets' => ['john@example.org']])
        ->assertStatus(409)
        ->assertJsonPath('code', 'catch_all_unsupported')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'can’t read the Postfix configuration'));

    expect(($this->rows)('@example.org'))->toBe([]);
});

it('refuses a catch-all when main.cf is unreadable, saying the configuration could not be read', function () {
    chmod("{$this->postfix}/main.cf", 0000);

    $this->putJson($this->url, ['targets' => ['john@example.org']])
        ->assertStatus(409)
        ->assertJsonPath('code', 'catch_all_unsupported')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'can’t read the Postfix configuration'));
})->skip(fn () => posix_getuid() === 0, 'root can read mode 0000 files');

it('refuses a catch-all when a renamed alias map is unreadable, since it might be email2email or not', function () {
    rename("{$this->postfix}/mariadb-email2email.cf", "{$this->postfix}/renamed.cf");
    chmod("{$this->postfix}/renamed.cf", 0000);
    file_put_contents("{$this->postfix}/main.cf", "virtual_alias_maps = mysql:{$this->postfix}/renamed.cf\n");

    $this->putJson($this->url, ['targets' => ['john@example.org']])
        ->assertStatus(409)
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'can’t read the Postfix configuration'));
})->skip(fn () => posix_getuid() === 0, 'root can read mode 0000 files');

it('accepts a renamed email2email map recognised by its query', function () {
    rename("{$this->postfix}/mariadb-email2email.cf", "{$this->postfix}/renamed.cf");
    file_put_contents("{$this->postfix}/main.cf", "virtual_alias_maps = mysql:{$this->postfix}/renamed.cf\n");

    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertCreated();
});

it('says in Finnish why a catch-all is refused', function () {
    supportCatchAll($this->postfix, false);
    $this->admin->forceFill(['locale' => 'fi'])->save();

    $this->putJson($this->url, ['targets' => ['john@example.org']])
        ->assertStatus(409)
        ->assertJsonPath('code', 'catch_all_unsupported')
        ->assertJsonPath('detail', fn (string $detail) => str_contains($detail, 'email2email') && str_contains($detail, 'catch-all'));
});

it('remembers the alias-map answer for 10 minutes', function () {
    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertCreated();
    supportCatchAll($this->postfix, false);

    $this->travel(9)->minutes();
    $this->putJson($this->url, ['targets' => ['mary@example.org']])->assertOk();

    $this->travel(2)->minutes();
    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertStatus(409)->assertJsonPath('code', 'catch_all_unsupported');
    expect(($this->rows)('@example.org'))->toBe([[10, '@example.org', 'mary@example.org']]);
});

it('lets a manager set and remove a catch-all', function () {
    $manager = User::factory()->create();
    grantCatchAll($manager, 'manager');
    $this->actingAs($manager);

    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertCreated();
    $this->deleteJson($this->url)->assertNoContent();

    expect(AuditEntry::query()->pluck('action')->all())->toBe(['forwarding.catch_all_set', 'forwarding.catch_all_removed']);
});

it('refuses a viewer with 403, before the server check', function () {
    supportCatchAll($this->postfix, false);
    $viewer = User::factory()->create();
    grantCatchAll($viewer, 'viewer');
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 10, 'source' => '@example.org', 'destination' => 'john@example.org']);
    $this->actingAs($viewer);

    $this->putJson($this->url, ['targets' => ['mary@example.org']])->assertForbidden()->assertJsonPath('code', 'forbidden');
    $this->deleteJson($this->url)->assertForbidden();

    expect(($this->rows)('@example.org'))->toBe([[10, '@example.org', 'john@example.org']]);
});

it('answers 404 to a user with no role on the domain', function () {
    $this->actingAs(User::factory()->create());

    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertNotFound();
    $this->deleteJson($this->url)->assertNotFound();
});

it('never lets a mailbox owner set a catch-all: the console routes are not theirs', function () {
    Auth::forgetGuards(); // signed out of the console: only the mailbox session is left
    $owner = Auth::guard('mailbox')->getProvider()->retrieveById('john@example.org');
    expect($owner)->toBeInstanceOf(MailboxPrincipal::class);
    $this->actingAs($owner, 'mailbox');

    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertUnauthorized();
    expect(($this->rows)('@example.org'))->toBe([]);
});

it('validates catch-all targets like other forwarding', function (array $body, string $field) {
    $this->putJson($this->url, $body)->assertUnprocessable()
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors([$field]);

    expect(($this->rows)('@example.org'))->toBe([]);
})->with([
    'no targets' => [['targets' => []], 'targets'],
    'missing targets' => [[], 'targets'],
    'more than 50' => [['targets' => array_map(fn (int $i) => "t{$i}@gmail.com", range(1, 51))], 'targets'],
    'incomplete address' => [['targets' => ['john@example.org', 'nobody']], 'targets.1'],
    'plus in the domain part' => [['targets' => ['x@gm+ail.com']], 'targets.0'],
    'not a string' => [['targets' => [['x']]], 'targets.0'],
    'a kept copy' => [['targets' => ['john@example.org'], 'keep_copy' => true], 'keep_copy'],
]);

it('says why a catch-all keeps no copy', function () {
    $this->putJson($this->url, ['targets' => ['john@example.org'], 'keep_copy' => true])
        ->assertUnprocessable()
        ->assertJsonPath('errors.keep_copy.0', 'A catch-all has no mailbox to keep a copy in.');
});

it('removes the catch-all and audits its old targets', function () {
    $this->putJson($this->url, ['targets' => ['john@example.org', 'out@gmail.com']])->assertCreated();

    $this->deleteJson($this->url)->assertNoContent();

    expect(($this->rows)('@example.org'))->toBe([]);
    $audit = AuditEntry::query()->where('action', 'forwarding.catch_all_removed')->sole();
    expect($audit->object_key)->toBe('@example.org')
        ->and($audit->domain_name)->toBe('example.org')
        ->and($audit->changes)->toBe(['removed' => 2, 'old' => ['john@example.org', 'out@gmail.com'], 'new' => []])
        ->and(AuditEntry::query()->where('action', 'forwarding.delete')->exists())->toBeFalse();
});

it('answers 404 when there is no catch-all to remove', function () {
    $this->deleteJson($this->url)->assertNotFound();
    expect(AuditEntry::query()->count())->toBe(0);
});

it('removes a legacy catch-all even where the server cannot support one, since removing it only helps', function () {
    supportCatchAll($this->postfix, false);
    DB::table(GuideSchema::table('virtual_aliases'))->insert(['domain_id' => 10, 'source' => '@example.org', 'destination' => 'john@example.org']);

    $this->deleteJson('/api/v1/domains/example.org/forwarding/%40')->assertNoContent();

    expect(($this->rows)('@example.org'))->toBe([]);
});

it('lists the catch-all row with the other forwarding', function () {
    $this->putJson($this->url, ['targets' => ['john@example.org']])->assertCreated();

    $data = $this->getJson('/api/v1/domains/example.org/forwarding')->assertOk()->json('data');

    expect(collect($data)->firstWhere('catch_all', true))->toBe([
        'local' => '', 'source' => '@example.org', 'source_is_mailbox' => false, 'catch_all' => true, 'keep_copy' => false,
        'targets' => [['address' => 'john@example.org', 'kind' => 'mailbox']],
    ])->and(collect($data)->firstWhere('source', 'jack@example.org')['catch_all'])->toBeFalse();
});

it('leaves existing mailboxes and their forwarding alone, so email2email still delivers their own mail', function () {
    $mailboxes = fn () => DB::table(GuideSchema::table('virtual_users'))->orderBy('id')->pluck('email')->all();
    $others = fn () => DB::table(GuideSchema::table('virtual_aliases'))->where('source', '<>', '@example.org')->orderBy('id')
        ->get()->map(fn ($r) => [$r->source, $r->destination])->all();
    $before = [$mailboxes(), $others()];

    $this->putJson($this->url, ['targets' => ['mary@example.org']])->assertCreated();

    expect([$mailboxes(), $others()])->toBe($before)
        ->and($before[1])->toBe([['jack@example.org', 'john@example.org']]);

    // Postfix looks john@ up in every virtual_alias_maps entry: email2email answers john@ itself, so the
    // catch-all (`@example.org`) never applies to him. An address with no mailbox gets nothing there and
    // falls through to the catch-all. (Mail truth checks the delivery itself.)
    $email2email = fn (string $address) => array_map(
        fn ($r) => $r->email,
        DB::select(str_replace('virtual_users', GuideSchema::table('virtual_users'), str_replace("'%s'", '?', AliasMaps::EMAIL2EMAIL_QUERY)), [$address]),
    );
    expect($email2email('john@example.org'))->toBe(['john@example.org'])
        ->and($email2email('nobody@example.org'))->toBe([])
        ->and(($this->rows)('@example.org'))->toBe([[10, '@example.org', 'mary@example.org']]);
});

it('keeps the ordinary forwarding routes for named sources', function () {
    $this->putJson('/api/v1/domains/example.org/forwarding/sales', ['targets' => ['john@example.org']])->assertCreated()
        ->assertJsonPath('catch_all', false);

    expect(AuditEntry::query()->pluck('action')->all())->toBe(['forwarding.replace']);
});
