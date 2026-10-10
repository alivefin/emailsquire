<?php

use App\Models\HelperTask;
use EmailSquire\Helpers\MaildirHelper;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;
use Tests\Support\TempTree;

/** Queues a restore the way the web app would: the payload names an archive timestamp or null for the newest. */
function maildirRestoreTask(string $address, mixed $payload = ['from' => null]): HelperTask
{
    $task = new HelperTask;
    $task->forceFill([
        'type' => 'maildir.restore',
        'address' => $address,
        'domain_name' => 'example.org',
        'payload' => $payload,
        'status' => 'pending',
        'run_after' => now()->subMinute(),
    ])->save();

    return $task;
}

/** Re-creates the mailbox, which a restore requires. */
function maildirRestoreMailbox(string $address): void
{
    DB::table(config('emailsquire.guide_schema').'.virtual_users')->insert(['domain_id' => 10, 'email' => $address, 'password' => 'x']);
}

/** Makes an archive whose one message says which archive it came from. */
function maildirRestoreArchive(string $path): void
{
    mkdir("{$path}/cur", 0700, true);
    file_put_contents("{$path}/cur/1.mail", 'from '.basename($path));
}

beforeEach(function () {
    GuideFixture::load('trixie');

    $this->tmp = TempTree::create();
    $this->root = "{$this->tmp}/vmail";
    $this->trash = "{$this->root}/.emailsquire-trash";
    mkdir("{$this->root}/example.org", 0700, true);
    mkdir("{$this->tmp}/outside", 0700);

    $this->helper = fn () => new MaildirHelper(
        DB::connection()->getPdo(),
        (string) config('emailsquire.guide_schema'),
        DB::connection()->getDatabaseName(),
        $this->root,
        $this->trash,
        fn (): int => gmmktime(12, 0, 0, 10, 10, 2026),
        fn (string $line) => null,
    );
});

afterEach(fn () => TempTree::remove($this->tmp));

it('restores the newest archive when from is null', function () {
    maildirRestoreMailbox('back@example.org');
    maildirRestoreArchive("{$this->trash}/example.org/back-20261001000000");
    maildirRestoreArchive("{$this->trash}/example.org/back-20261005000000");
    $task = maildirRestoreTask('back@example.org');

    expect(($this->helper)()->run())->toBe(['done' => 1, 'failed' => 0, 'retried' => 0]);

    expect($task->refresh()->status)->toBe('done')
        ->and($task->note)->toBe('restored example.org/back-20261005000000')
        ->and(file_get_contents("{$this->root}/example.org/back/cur/1.mail"))->toBe('from back-20261005000000')
        ->and(TempTree::snapshot("{$this->trash}/example.org"))->toBe([
            'back-20261001000000' => 'dir',
            'back-20261001000000/cur' => 'dir',
            'back-20261001000000/cur/1.mail' => 'file:from back-20261001000000',
        ]);
});

it('restores the named archive', function () {
    maildirRestoreMailbox('back@example.org');
    maildirRestoreArchive("{$this->trash}/example.org/back-20261001000000");
    maildirRestoreArchive("{$this->trash}/example.org/back-20261005000000");
    $task = maildirRestoreTask('back@example.org', ['from' => '20261001000000']);

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('done')
        ->and($task->note)->toBe('restored example.org/back-20261001000000')
        ->and(file_get_contents("{$this->root}/example.org/back/cur/1.mail"))->toBe('from back-20261001000000')
        ->and("{$this->trash}/example.org/back-20261005000000")->toBeDirectory();
});

it('ignores archives of a local part that only shares a prefix', function () {
    maildirRestoreMailbox('a@example.org');
    maildirRestoreArchive("{$this->trash}/example.org/a-20261001000000");
    maildirRestoreArchive("{$this->trash}/example.org/a-b-20261005000000");
    $task = maildirRestoreTask('a@example.org');

    ($this->helper)()->run();

    expect($task->refresh()->note)->toBe('restored example.org/a-20261001000000')
        ->and(file_get_contents("{$this->root}/example.org/a/cur/1.mail"))->toBe('from a-20261001000000')
        ->and("{$this->trash}/example.org/a-b-20261005000000")->toBeDirectory();
});

it('restores a mixed-case legacy address exactly as stored', function () {
    maildirRestoreMailbox('Anna@Example.org');
    maildirRestoreArchive("{$this->trash}/Example.org/Anna-20261001000000");
    maildirRestoreArchive("{$this->trash}/Example.org/anna-20261005000000");
    $task = maildirRestoreTask('Anna@Example.org');

    ($this->helper)()->run();

    expect($task->refresh()->note)->toBe('restored Example.org/Anna-20261001000000')
        ->and(file_get_contents("{$this->root}/Example.org/Anna/cur/1.mail"))->toBe('from Anna-20261001000000');
});

it('refuses when the mailbox does not exist', function () {
    maildirRestoreArchive("{$this->trash}/example.org/back-20261005000000");
    maildirRestoreMailbox('Back@example.org');
    $before = TempTree::snapshot($this->root);
    $task = maildirRestoreTask('back@example.org');

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 1, 'retried' => 0]);

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toBe('restore refused: the mailbox does not exist')
        ->and(TempTree::snapshot($this->root))->toBe($before);
});

it('refuses when the maildir already exists', function () {
    maildirRestoreMailbox('back@example.org');
    maildirRestoreArchive("{$this->trash}/example.org/back-20261005000000");
    TempTree::maildir("{$this->root}/example.org/back");
    $before = TempTree::snapshot($this->root);
    $task = maildirRestoreTask('back@example.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toBe('restore refused: the maildir already exists')
        ->and(TempTree::snapshot($this->root))->toBe($before);
});

it('refuses when no archive matches', function (array $payload, array $archives) {
    maildirRestoreMailbox('back@example.org');
    foreach ($archives as $archive) {
        maildirRestoreArchive("{$this->trash}/{$archive}");
    }
    $before = TempTree::snapshot($this->root);
    $task = maildirRestoreTask('back@example.org', $payload);

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toBe('restore refused: no matching archive')
        ->and(TempTree::snapshot($this->root))->toBe($before);
})->with([
    'named archive missing' => [['from' => '20261001000000'], ['example.org/back-20261005000000']],
    'only near misses' => [['from' => null], [
        'example.org/backup-20261005000000',
        'example.org/back-2026100500000',
        'example.org/back-20261005000000.old',
        'example.org/Back-20261005000000',
        'other.org/back-20261005000000',
    ]],
]);

it('refuses when the trash has nothing for the domain', function () {
    maildirRestoreMailbox('back@example.org');
    $task = maildirRestoreTask('back@example.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->last_error)->toBe('restore refused: no matching archive')
        ->and("{$this->root}/example.org/back")->not->toBeDirectory();
});

it('refuses a malformed from', function (mixed $payload) {
    maildirRestoreMailbox('back@example.org');
    maildirRestoreArchive("{$this->trash}/example.org/back-20261005000000");
    $before = TempTree::snapshot($this->root);
    $task = maildirRestoreTask('back@example.org', $payload);

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toBe('restore refused: malformed from')
        ->and(TempTree::snapshot($this->root))->toBe($before);
})->with([
    'climbing out' => [['from' => '../x']],
    'too short' => [['from' => '2026100500000']],
    'trailing newline' => [['from' => "20261005000000\n"]],
    'a number' => [['from' => 20261005000000]],
    'no from key' => [[]],
    'no payload' => [null],
]);

it('refuses a symlinked archive or trash domain directory', function (string $link) {
    maildirRestoreMailbox('back@example.org');
    maildirRestoreArchive("{$this->tmp}/outside/back-20261005000000");
    match ($link) {
        'archive' => (function () {
            mkdir("{$this->trash}/example.org", 0700, true);
            symlink("{$this->tmp}/outside/back-20261005000000", "{$this->trash}/example.org/back-20261005000000");
        })(),
        'trash domain' => (function () {
            mkdir($this->trash, 0700);
            symlink("{$this->tmp}/outside", "{$this->trash}/example.org");
        })(),
    };
    $task = maildirRestoreTask('back@example.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toContain('symlink')
        ->and("{$this->root}/example.org/back")->not->toBeDirectory()
        ->and("{$this->tmp}/outside/back-20261005000000/cur/1.mail")->toBeFile();
})->with(['archive', 'trash domain']);

it('refuses a symlinked target domain directory', function () {
    maildirRestoreMailbox('back@linked.org');
    maildirRestoreArchive("{$this->trash}/linked.org/back-20261005000000");
    symlink("{$this->tmp}/outside", "{$this->root}/linked.org");
    $task = maildirRestoreTask('back@linked.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->last_error)->toContain('symlink')
        ->and("{$this->trash}/linked.org/back-20261005000000")->toBeDirectory()
        ->and(TempTree::snapshot("{$this->tmp}/outside"))->toBe([]);
});

it('creates the domain directory when it is missing', function () {
    rmdir("{$this->root}/example.org");
    maildirRestoreMailbox('back@example.org');
    maildirRestoreArchive("{$this->trash}/example.org/back-20261005000000");
    $task = maildirRestoreTask('back@example.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('done')
        ->and(fileperms("{$this->root}/example.org") & 0777)->toBe(0700)
        ->and("{$this->root}/example.org/back/cur/1.mail")->toBeFile();
});

it('retries while the mail root is missing', function () {
    maildirRestoreMailbox('back@example.org');
    maildirRestoreArchive("{$this->tmp}/trash/example.org/back-20261005000000");
    $this->trash = "{$this->tmp}/trash";
    TempTree::remove($this->root);
    $task = maildirRestoreTask('back@example.org');

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 0, 'retried' => 1])
        ->and($task->refresh()->status)->toBe('pending')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toBe('mail root is missing')
        ->and("{$this->trash}/example.org/back-20261005000000")->toBeDirectory();
});
