<?php

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Usage\RefreshUsage;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Support\GuideFixture;

const USAGE_OLD = '2026-01-01 00:00:00';

beforeEach(function () {
    GuideFixture::load('trixie');
    $this->root = sys_get_temp_dir().'/emailsquire-usage-'.bin2hex(random_bytes(4));
    mkdir($this->root);
    config()->set('emailsquire.paths.mail_root', $this->root);
});

afterEach(function () {
    if (is_dir($this->root)) {
        rmdir($this->root);
    }
});

function storageReply(int $used): array
{
    return ['doveadmResponse', [['type' => 'STORAGE', 'value' => (string) $used, 'limit' => '1000']]];
}

function oldUsageRow(string $email, int $used = 1): void
{
    DB::table('mailbox_usage')->insert(['email' => $email, 'used_kib' => $used, 'limit_kib' => 5, 'checked_at' => USAGE_OLD]);
}

it('snapshots every mailbox and the mail root', function () {
    fakeDoveadmBatch(fn (string $command, array $params) => storageReply($params['user'] === 'john@example.org' ? 111 : 222));

    $result = app(RefreshUsage::class)->run();

    expect($result->checked)->toBe(2)->and($result->skipped)->toBe(0)->and($result->complete)->toBeTrue()->and($result->error)->toBeNull();
    expect(DB::table('mailbox_usage')->orderBy('email')->pluck('used_kib', 'email')->all())
        ->toBe(['john@example.org' => 111, 'mary@example.org' => 222]);
    $row = DB::table('server_storage')->first();
    expect($row->id)->toBe(1)->and($row->total_bytes)->toBeGreaterThan(0)
        ->and(now()->diffInSeconds($row->checked_at, true))->toBeLessThan(10);
});

it('skips users doveadm does not know and keeps their old figures', function () {
    oldUsageRow('mary@example.org', 7);
    fakeDoveadmBatch(fn (string $command, array $params) => $params['user'] === 'mary@example.org'
        ? ['error', ['type' => 'exitCode', 'exitCode' => 67]]
        : storageReply(50));

    $result = app(RefreshUsage::class)->run();

    expect($result->checked)->toBe(1)->and($result->skipped)->toBe(1)->and($result->complete)->toBeTrue();
    $mary = DB::table('mailbox_usage')->where('email', 'mary@example.org')->first();
    expect($mary->used_kib)->toBe(7)->and($mary->checked_at)->toBe(USAGE_OLD);
    expect(DB::table('mailbox_usage')->where('email', 'john@example.org')->value('used_kib'))->toBe(50);
});

it('skips a mailbox whose reply has no usable storage entry', function () {
    fakeDoveadmBatch(fn () => ['doveadmResponse', [['type' => 'MESSAGE', 'value' => '1', 'limit' => '-']]]);

    $result = app(RefreshUsage::class)->run();

    expect($result->checked)->toBe(0)->and($result->skipped)->toBe(2)->and($result->complete)->toBeTrue();
    expect(DB::table('mailbox_usage')->count())->toBe(0);
});

it('prunes rows for mailboxes that no longer exist after a complete run', function () {
    oldUsageRow('gone@example.org');
    oldUsageRow('John@Example.org'); // legacy mixed case row for an existing mailbox: pruning is case-insensitive
    fakeDoveadmBatch(fn () => storageReply(5));

    app(RefreshUsage::class)->run();

    expect(DB::table('mailbox_usage')->pluck('email')->sort()->values()->all())
        ->toContain('mary@example.org')
        ->not->toContain('gone@example.org');
    expect(DB::table('mailbox_usage')->whereRaw('LOWER(email) = ?', ['john@example.org'])->count())->toBeGreaterThanOrEqual(1);
});

it('stops when doveadm becomes unreachable, keeping old figures and orphans', function () {
    insertMailbox('a@example.org', 'x');
    insertMailbox('b@example.org', 'x');
    foreach (['john', 'mary', 'a', 'b'] as $local) {
        oldUsageRow("{$local}@example.org");
    }
    oldUsageRow('gone@example.org');

    $requests = 0;
    Http::fake(function ($request) use (&$requests) {
        if (++$requests === 2) {
            throw new ConnectionException('refused');
        }

        return Http::response(array_map(fn (array $c) => ['doveadmResponse', [['type' => 'STORAGE', 'value' => '99', 'limit' => '-']], $c[2]], $request->data()));
    });

    $result = app()->make(RefreshUsage::class, ['doveadm' => app(DoveadmClient::class), 'page' => 2, 'batch' => 2])->run();

    expect($result->complete)->toBeFalse()->and($result->error)->not->toBeNull()->and($result->checked)->toBe(2);
    $rows = DB::table('mailbox_usage')->pluck('checked_at', 'email');
    expect($rows['john@example.org'])->not->toBe(USAGE_OLD)->and($rows['mary@example.org'])->not->toBe(USAGE_OLD)
        ->and($rows['a@example.org'])->toBe(USAGE_OLD)->and($rows['b@example.org'])->toBe(USAGE_OLD)
        ->and($rows)->toHaveKey('gone@example.org');
    expect(DB::table('server_storage')->count())->toBe(0);
});

it('skips the server row when the mail root is missing', function () {
    config()->set('emailsquire.paths.mail_root', $this->root.'/missing');
    fakeDoveadmBatch(fn () => storageReply(5));

    $result = app(RefreshUsage::class)->run();

    expect($result->complete)->toBeTrue()->and(DB::table('server_storage')->count())->toBe(0)
        ->and(DB::table('mailbox_usage')->count())->toBe(2);
});

it('the command prints the counts and exits 0', function () {
    fakeDoveadmBatch(fn () => storageReply(5));

    $this->artisan('emailsquire:refresh-usage')->expectsOutputToContain('Checked 2 mailboxes, skipped 0.')->assertExitCode(0);
});

it('the command exits 1 and prints the error when the run is incomplete', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    $this->artisan('emailsquire:refresh-usage')->expectsOutputToContain('doveadm API unreachable')->assertExitCode(1);
});

it('is scheduled every 15 minutes with a 30 minute overlap mutex', function () {
    $this->app->make(Kernel::class)->bootstrap();

    $events = collect(app(Schedule::class)->events())->filter(fn (Event $e) => str_contains($e->command ?? '', 'emailsquire:refresh-usage'));

    expect($events)->toHaveCount(1)
        ->and($events->first()->expression)->toBe('*/15 * * * *')
        ->and($events->first()->withoutOverlapping)->toBeTrue()
        ->and($events->first()->expiresAt)->toBe(30);
});
