<?php

use App\Models\HelperTask;
use EmailSquire\Helpers\MaildirHelper;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

/** Inserts a helper task the way the web app would, with any column overridden. */
function maildirHelperTask(string $address, array $over = []): HelperTask
{
    $task = new HelperTask;
    $task->forceFill([
        'type' => 'maildir.archive',
        'address' => $address,
        'domain_name' => 'example.org',
        'status' => 'pending',
        'run_after' => now()->subMinute(),
        ...$over,
    ])->save();

    return $task;
}

/** Every path under $dir, without following symlinks: relative path => 'dir', 'file:<contents>' or 'link:<target>'. */
function maildirHelperTree(string $dir): array
{
    $tree = [];
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );
    foreach ($items as $item) {
        $relative = substr($item->getPathname(), strlen($dir) + 1);
        $tree[$relative] = match (true) {
            $item->isLink() => 'link:'.readlink($item->getPathname()),
            $item->isDir() => 'dir',
            default => 'file:'.file_get_contents($item->getPathname()),
        };
    }
    ksort($tree);

    return $tree;
}

/** Makes a maildir with one message in it. */
function maildirHelperMaildir(string $path): void
{
    mkdir("{$path}/cur", 0700, true);
    file_put_contents("{$path}/cur/1.mail", "Subject: hi\n");
}

beforeEach(function () {
    GuideFixture::load('trixie');

    $this->tmp = sys_get_temp_dir().'/emailsquire-maildir-'.bin2hex(random_bytes(6));
    $this->root = "{$this->tmp}/vmail";
    $this->trash = "{$this->root}/.emailsquire-trash";
    mkdir("{$this->root}/example.org", 0700, true);
    mkdir("{$this->tmp}/outside", 0700);
    $this->log = [];

    $this->helper = fn () => new MaildirHelper(
        DB::connection()->getPdo(),
        (string) config('emailsquire.guide_schema'),
        DB::connection()->getDatabaseName(),
        $this->root,
        $this->trash,
        fn (): int => gmmktime(12, 0, 0, 10, 10, 2026),
        function (string $line): void {
            $this->log[] = $line;
        },
    );
});

afterEach(function () {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($this->tmp);
});

it('archives a deleted mailbox maildir', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    $task = maildirHelperTask('gone@example.org');

    expect(($this->helper)()->run())->toBe(['done' => 1, 'failed' => 0, 'retried' => 0]);

    $task->refresh();
    expect("{$this->root}/example.org/gone")->not->toBeDirectory()
        ->and(file_get_contents("{$this->trash}/example.org/gone-20261010120000/cur/1.mail"))->toBe("Subject: hi\n")
        ->and($task->status)->toBe('done')
        ->and($task->note)->toBe('archived to example.org/gone-20261010120000')
        ->and($task->last_error)->toBeNull()
        ->and($this->log)->toHaveCount(1);
});

it('archives a mixed-case legacy address exactly as stored', function () {
    maildirHelperMaildir("{$this->root}/Example.org/Anna");
    $task = maildirHelperTask('Anna@Example.org', ['domain_name' => 'example.org']);

    ($this->helper)()->run();

    expect("{$this->root}/Example.org/Anna")->not->toBeDirectory()
        ->and("{$this->trash}/Example.org/Anna-20261010120000/cur/1.mail")->toBeFile()
        ->and($task->refresh()->status)->toBe('done');
});

it('marks a missing maildir done with a note', function () {
    $task = maildirHelperTask('nobody@example.org');
    $other = maildirHelperTask('nobody@missing.example');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('done')
        ->and($task->note)->toBe('no maildir to archive')
        ->and($other->refresh()->status)->toBe('done')
        ->and("{$this->trash}")->not->toBeDirectory();
});

it('leaves the maildir in place when the mailbox exists again', function () {
    maildirHelperMaildir("{$this->root}/example.org/john");
    $before = maildirHelperTree($this->root);
    $task = maildirHelperTask('john@example.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('done')
        ->and($task->note)->toBe('mailbox exists again; maildir left in place')
        ->and(maildirHelperTree($this->root))->toBe($before);
});

it('refuses hostile addresses without touching the domain directory', function (string $address) {
    maildirHelperMaildir("{$this->root}/example.org/a");
    maildirHelperMaildir("{$this->root}/example.org/b");
    maildirHelperMaildir("{$this->root}/example.org/a..b");
    file_put_contents("{$this->root}/example.org/keep", 'x');
    $before = maildirHelperTree($this->root);
    $task = maildirHelperTask($address);

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 1, 'retried' => 0]);

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toStartWith('unsafe address: ')
        ->and(maildirHelperTree($this->root))->toBe($before);
})->with([
    'no local part' => ['@example.org'],
    'no domain' => ['x@'],
    'dot local part' => ['.@example.org'],
    'dot-dot' => ['a..b@example.org'],
    'hidden domain' => ['a@.hidden'],
    'slash in local part' => ['a/b@example.org'],
    'domain climbing out' => ['a@example.org/..'],
    'NUL' => ["a\0@example.org"],
    'empty' => [''],
]);

it('refuses a symlinked maildir or domain directory', function (string $link) {
    maildirHelperMaildir("{$this->tmp}/outside/gone");
    match ($link) {
        'maildir' => symlink("{$this->tmp}/outside/gone", "{$this->root}/example.org/gone"),
        'domain' => symlink("{$this->tmp}/outside", "{$this->root}/linked.org"),
        'trash domain' => (function () {
            maildirHelperMaildir("{$this->root}/example.org/gone");
            mkdir($this->trash, 0700);
            symlink("{$this->tmp}/outside", "{$this->trash}/example.org");
        })(),
    };
    $task = maildirHelperTask($link === 'domain' ? 'gone@linked.org' : 'gone@example.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toContain('symlink')
        ->and(maildirHelperTree("{$this->tmp}/outside"))->toBe(['gone' => 'dir', 'gone/cur' => 'dir', 'gone/cur/1.mail' => "file:Subject: hi\n"]);
})->with(['maildir', 'domain', 'trash domain']);

it('never overwrites a taken archive name', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    mkdir("{$this->trash}/example.org/gone-20261010120000", 0700, true);
    $task = maildirHelperTask('gone@example.org');

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 0, 'retried' => 1]);

    $task->refresh();
    $wait = DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), run_after) AS s FROM helper_tasks WHERE id = ?', [$task->id])->s;
    expect($task->status)->toBe('pending')
        ->and($task->attempts)->toBe(1)
        ->and($task->last_error)->toBe('archive name taken: example.org/gone-20261010120000')
        ->and($wait)->toBeGreaterThanOrEqual(115)->toBeLessThanOrEqual(125)
        ->and("{$this->root}/example.org/gone/cur/1.mail")->toBeFile()
        ->and(maildirHelperTree("{$this->trash}/example.org/gone-20261010120000"))->toBe([]);
});

it('backs off exponentially between I/O errors', function (int $attempts, int $minutes) {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    mkdir("{$this->trash}/example.org/gone-20261010120000", 0700, true);
    $task = maildirHelperTask('gone@example.org', ['attempts' => $attempts]);

    ($this->helper)()->run();

    $wait = DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), run_after) AS s FROM helper_tasks WHERE id = ?', [$task->id])->s;
    expect($task->refresh()->attempts)->toBe($attempts + 1)
        ->and($task->status)->toBe('pending')
        ->and($wait)->toBeGreaterThanOrEqual($minutes * 60 - 5)->toBeLessThanOrEqual($minutes * 60 + 5);
})->with([[1, 4], [2, 8], [3, 16]]);

it('fails after five I/O errors', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    mkdir("{$this->trash}/example.org/gone-20261010120000", 0700, true);
    $task = maildirHelperTask('gone@example.org', ['attempts' => 4]);

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 1, 'retried' => 0]);

    expect($task->refresh()->status)->toBe('failed')
        ->and($task->attempts)->toBe(5)
        ->and("{$this->root}/example.org/gone")->toBeDirectory();
});

it('skips tasks not yet due and other task types', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    $future = maildirHelperTask('gone@example.org', ['run_after' => now()->addHour()]);
    $dkim = maildirHelperTask('gone@example.org', ['type' => 'dkim.publish']);

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 0, 'retried' => 0]);

    expect($future->refresh()->status)->toBe('pending')
        ->and($dkim->refresh()->status)->toBe('pending')
        ->and("{$this->root}/example.org/gone")->toBeDirectory();
});

it('runs at most the given number of tasks, oldest first', function () {
    $first = maildirHelperTask('one@example.org');
    $second = maildirHelperTask('two@example.org');

    expect(($this->helper)()->run(1))->toBe(['done' => 1, 'failed' => 0, 'retried' => 0])
        ->and($first->refresh()->status)->toBe('done')
        ->and($second->refresh()->status)->toBe('pending');
});

it('resumes tasks left running by a crashed run', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    $task = maildirHelperTask('gone@example.org', ['status' => 'running']);
    $dkim = maildirHelperTask('x@example.org', ['type' => 'dkim.publish', 'status' => 'running']);

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('done')
        ->and("{$this->trash}/example.org/gone-20261010120000")->toBeDirectory()
        ->and($dkim->refresh()->status)->toBe('running');
});

it('creates the trash directories 0700', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    maildirHelperTask('gone@example.org');

    ($this->helper)()->run();

    expect(fileperms($this->trash) & 0777)->toBe(0700)
        ->and(fileperms("{$this->trash}/example.org") & 0777)->toBe(0700);
});

it('retries while the mail root is missing', function () {
    rename($this->root, "{$this->tmp}/elsewhere");
    $task = maildirHelperTask('gone@example.org');

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 0, 'retried' => 1])
        ->and($task->refresh()->last_error)->toBe('mail root is missing');
});

it('fails unknown maildir task types and restores until restore exists', function () {
    $unknown = maildirHelperTask('gone@example.org', ['type' => 'maildir.shred']);
    $restore = maildirHelperTask('gone@example.org', ['type' => 'maildir.restore', 'payload' => ['from' => null]]);

    expect(($this->helper)()->run())->toBe(['done' => 0, 'failed' => 2, 'retried' => 0])
        ->and($unknown->refresh()->last_error)->toBe('unknown task type')
        ->and($restore->refresh()->last_error)->toBe('restore not implemented')
        ->and($restore->status)->toBe('failed');
});

it('refuses schema names that are not plain identifiers', function (string $guide, string $app) {
    expect(fn () => new MaildirHelper(DB::connection()->getPdo(), $guide, $app, $this->root, $this->trash))
        ->toThrow(InvalidArgumentException::class);
})->with([['mail`server', 'emailsquire'], ['mailserver', 'a;b'], ['', 'emailsquire']]);

it('refuses relative mail root or trash paths', function (string $root, string $trash) {
    expect(fn () => new MaildirHelper(DB::connection()->getPdo(), 'mailserver', 'emailsquire', $root, $trash))
        ->toThrow(InvalidArgumentException::class);
})->with([['vmail', '/var/vmail/.emailsquire-trash'], ['/var/vmail', 'trash']]);

it('matches the mailbox exactly as stored, case included', function (string $fixture, string $live, bool $archived) {
    GuideFixture::load($fixture);
    DB::table(config('emailsquire.guide_schema').'.virtual_users')->insert(['domain_id' => 10, 'email' => $live, 'password' => 'x']);
    maildirHelperMaildir("{$this->root}/Example.org/Anna");
    $task = maildirHelperTask('Anna@Example.org');

    ($this->helper)()->run();

    expect($task->refresh()->status)->toBe('done')
        ->and(is_dir("{$this->root}/Example.org/Anna"))->toBe(! $archived)
        ->and(is_dir("{$this->trash}/Example.org/Anna-20261010120000"))->toBe($archived)
        ->and($task->note)->toBe($archived ? 'archived to Example.org/Anna-20261010120000' : 'mailbox exists again; maildir left in place');
})->with([
    'trixie, other case live' => ['trixie', 'anna@example.org', true],
    'trixie, exact spelling live' => ['trixie', 'Anna@Example.org', false],
    'restored utf8mb3, other case live' => ['restored-bookworm', 'anna@example.org', true],
    'restored utf8mb3, exact spelling live' => ['restored-bookworm', 'Anna@Example.org', false],
]);

it('retries a task whose guide lookup throws and still runs the next task', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    $broken = maildirHelperTask('gone@example.org');
    $next = maildirHelperTask('@example.org');
    $helper = new MaildirHelper(
        DB::connection()->getPdo(), 'emailsquire_no_such_schema', DB::connection()->getDatabaseName(),
        $this->root, $this->trash, fn (): int => 0, fn (string $line) => null,
    );

    expect($helper->run())->toBe(['done' => 0, 'failed' => 1, 'retried' => 1]);

    $wait = DB::selectOne('SELECT TIMESTAMPDIFF(SECOND, NOW(), run_after) AS s FROM helper_tasks WHERE id = ?', [$broken->id])->s;
    expect($broken->refresh()->status)->toBe('pending')
        ->and($broken->attempts)->toBe(1)
        ->and($broken->last_error)->toStartWith('PDOException: ')
        ->and($wait)->toBeGreaterThanOrEqual(115)->toBeLessThanOrEqual(125)
        ->and("{$this->root}/example.org/gone")->toBeDirectory()
        ->and($next->refresh()->status)->toBe('failed');
});

it('logs the archive name before recording it, so a failed status write leaves a trace', function () {
    maildirHelperMaildir("{$this->root}/example.org/gone");
    $task = maildirHelperTask('gone@example.org');
    DB::unprepared("CREATE TRIGGER maildir_helper_test_no_done BEFORE UPDATE ON helper_tasks FOR EACH ROW
        BEGIN IF NEW.status = 'done' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'status write failed'; END IF; END");

    try {
        expect(fn () => ($this->helper)()->run())->toThrow(PDOException::class, 'status write failed');
    } finally {
        DB::unprepared('DROP TRIGGER maildir_helper_test_no_done');
    }

    expect($this->log)->toBe(["task {$task->id} done: archived to example.org/gone-20261010120000"])
        ->and($task->refresh()->status)->toBe('running')
        ->and("{$this->trash}/example.org/gone-20261010120000/cur/1.mail")->toBeFile();

    // The next run picks the task up again; the maildir is gone, so it finishes without touching the archive.
    ($this->helper)()->run();
    expect($task->refresh()->status)->toBe('done')
        ->and($task->note)->toBe('no maildir to archive')
        ->and("{$this->trash}/example.org/gone-20261010120000/cur/1.mail")->toBeFile();
});
