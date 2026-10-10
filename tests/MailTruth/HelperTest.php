<?php

use App\Models\HelperTask;
use App\Models\MailboxUsage;
use App\Models\ServerStorage;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

const HELPER_TRASH = '/var/vmail/.emailsquire-trash/example.org';

beforeEach(function () {
    GuideFixture::load('trixie');

    $this->actingAs(User::factory()->create(['is_server_admin' => true]));

    // Apply exactly what `emailsquire:grants` prints for vmail: this proves those grants are enough for the helper.
    expect(Artisan::call('emailsquire:grants', ['--database' => 'migrator']))->toBe(0);
    $grants = array_filter(
        explode("\n", Artisan::output()),
        fn (string $line) => preg_match('/^(CREATE USER|GRANT) /', $line) === 1 && str_contains($line, "'vmail'@'localhost'"),
    );
    expect($grants)->toHaveCount(3);
    foreach ($grants as $line) {
        DB::connection('migrator')->statement($line);
    }
});

/** The trash entries for $local, which is unique to the test. */
function helperArchives(string $local): array
{
    return glob(HELPER_TRASH."/{$local}-*", GLOB_ONLYDIR) ?: [];
}

function messagesIn(string $maildir): int
{
    return count(glob("{$maildir}/Maildir/new/*") ?: []);
}

/** Creates <local>@example.org, delivers one message, deletes the mailbox and lets the helper archive it. */
function archiveFreshMailbox($test, string $local): void
{
    createApiMailbox($test, $local);
    sendMail("{$local}@example.org");
    expect(deliveredCount($local, 1))->toBe(1);

    $test->deleteJson("/api/v1/domains/example.org/mailboxes/{$local}")->assertSuccessful();

    $helper = runMaildirHelper('run');
    expect($helper->getExitCode())->toBe(0, $helper->getOutput().$helper->getErrorOutput());
}

it('archives a deleted maildir as vmail and unblocks the address', function () {
    $local = apiLocal('arch');
    createApiMailbox($this, $local);
    sendMail("{$local}@example.org");
    expect(deliveredCount($local, 1))->toBe(1);

    $this->deleteJson("/api/v1/domains/example.org/mailboxes/{$local}")->assertSuccessful();

    $recreate = fn () => $this->postJson('/api/v1/domains/example.org/mailboxes', [
        'local' => $local, 'password' => API_TEST_PASSWORD, 'quota_bytes' => 104857600,
    ]);
    $recreate()->assertStatus(409)->assertJsonPath('code', 'archiving');

    $helper = runMaildirHelper('run');

    expect($helper->getExitCode())->toBe(0, $helper->getOutput().$helper->getErrorOutput())
        ->and(file_exists("/var/vmail/example.org/{$local}"))->toBeFalse();

    $archives = helperArchives($local);
    expect($archives)->toHaveCount(1)
        ->and(basename($archives[0]))->toMatch('/^'.$local.'-\d{14}$/')
        ->and(messagesIn($archives[0]))->toBe(1)
        ->and(HelperTask::query()->where('address', "{$local}@example.org")->sole()->status)->toBe('done');

    $recreate()->assertCreated();
});

it('archives a legacy mixed-case mailbox from its stored-spelling maildir', function () {
    // Dovecot's home is the stored spelling: Leg…@Example.org lives in /var/vmail/Example.org/Leg…, not example.org/leg….
    $local = 'Leg'.bin2hex(random_bytes(3));
    $home = "/var/vmail/Example.org/{$local}";
    $trash = '/var/vmail/.emailsquire-trash/Example.org';
    insertMailbox("{$local}@Example.org", 'x');

    try {
        sendMail(strtolower($local).'@example.org');
        $deadline = microtime(true) + 10;
        while (messagesIn($home) < 1 && microtime(true) < $deadline) {
            usleep(200_000);
        }
        expect(messagesIn($home))->toBe(1)
            ->and(is_dir('/var/vmail/example.org/'.strtolower($local)))->toBeFalse();

        $this->deleteJson('/api/v1/domains/example.org/mailboxes/'.strtolower($local))->assertSuccessful();
        expect(HelperTask::query()->sole()->address)->toBe("{$local}@Example.org");

        $helper = runMaildirHelper('run');

        $archives = glob("{$trash}/{$local}-*", GLOB_ONLYDIR) ?: [];
        expect($helper->getExitCode())->toBe(0, $helper->getOutput().$helper->getErrorOutput())
            ->and(is_dir($home))->toBeFalse()
            ->and($archives)->toHaveCount(1)
            ->and(messagesIn($archives[0]))->toBe(1)
            ->and(HelperTask::query()->sole()->note)->toStartWith("archived to Example.org/{$local}-");
    } finally {
        // Example.org is this test's own; remove what it left so later runs start clean.
        foreach ([$home, ...(glob("{$trash}/{$local}-*", GLOB_ONLYDIR) ?: [])] as $dir) {
            File::deleteDirectory($dir);
        }
        @rmdir('/var/vmail/Example.org');
        @rmdir($trash);
    }
});

it('restores the archived maildir into the re-created mailbox', function () {
    $local = apiLocal('rest');
    archiveFreshMailbox($this, $local);
    expect(helperArchives($local))->toHaveCount(1)
        ->and(deliveredCount($local))->toBe(0);

    createApiMailbox($this, $local);

    expect(Artisan::call('emailsquire:restore-maildir', ['address' => "{$local}@example.org"]))->toBe(0, Artisan::output());

    $helper = runMaildirHelper('run');

    expect($helper->getExitCode())->toBe(0, $helper->getOutput().$helper->getErrorOutput())
        ->and(deliveredCount($local))->toBe(1)
        ->and(helperArchives($local))->toBe([])
        ->and(HelperTask::query()->where('type', 'maildir.restore')->where('address', "{$local}@example.org")->sole()->status)->toBe('done');
});

it('refreshes usage from the real doveadm', function () {
    $local = apiLocal('usage');
    createApiMailbox($this, $local);
    sendMail("{$local}@example.org");
    expect(deliveredCount($local, 1))->toBe(1);

    expect(Artisan::call('emailsquire:refresh-usage'))->toBe(0, Artisan::output());

    $usage = MailboxUsage::query()->findOrFail("{$local}@example.org");
    expect($usage->used_kib)->toBeGreaterThanOrEqual(1)
        ->and($usage->limit_kib)->toBe(102400)
        ->and(ServerStorage::query()->sole()->total_bytes)->toBeGreaterThan(0);
});
