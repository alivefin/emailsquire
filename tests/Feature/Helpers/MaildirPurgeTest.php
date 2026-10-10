<?php

use EmailSquire\Helpers\MaildirHelper;
use Illuminate\Support\Facades\DB;
use Tests\Support\TempTree;

beforeEach(function () {
    $this->tmp = TempTree::create();
    $this->root = "{$this->tmp}/vmail";
    $this->trash = "{$this->root}/.emailsquire-trash";
    mkdir("{$this->root}/example.org", 0700, true);
    mkdir("{$this->tmp}/outside", 0700);
    file_put_contents("{$this->tmp}/outside/keep", 'outside');
    $this->log = [];

    // Purge reads nothing from the database; the helper still needs a connection to be built.
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

afterEach(fn () => TempTree::remove($this->tmp));

it('purges entries older than the retention', function () {
    TempTree::maildir("{$this->trash}/example.org/old-20260901000000");
    TempTree::maildir("{$this->trash}/example.org/new-20261001000000");
    TempTree::maildir("{$this->trash}/gone.org/old-20260901000000");

    expect(($this->helper)()->purge(30))->toBe(['purged' => 2, 'refused' => 0]);

    expect(TempTree::snapshot($this->trash))->toBe([
        'example.org' => 'dir',
        'example.org/new-20261001000000' => 'dir',
        'example.org/new-20261001000000/cur' => 'dir',
        'example.org/new-20261001000000/cur/1.mail' => "file:Subject: hi\n",
    ])->and($this->log)->toBe([
        'purged example.org/old-20260901000000',
        'purged gone.org/old-20260901000000',
    ]);
});

it('keeps an entry until it is older than the retention', function () {
    // The clock reads 2026-10-10 12:00:00, so the cutoff is 2026-09-10 12:00:00.
    TempTree::maildir("{$this->trash}/example.org/edge-20260910120000");
    TempTree::maildir("{$this->trash}/example.org/older-20260910115959");

    expect(($this->helper)()->purge(30))->toBe(['purged' => 1, 'refused' => 0])
        ->and(array_keys(TempTree::snapshot("{$this->trash}/example.org")))->toContain('edge-20260910120000')
        ->not->toContain('older-20260910115959');
});

it('does not follow symlinks inside an entry', function () {
    TempTree::maildir("{$this->trash}/example.org/old-20260901000000");
    symlink("{$this->tmp}/outside/keep", "{$this->trash}/example.org/old-20260901000000/cur/link");
    symlink("{$this->tmp}/outside", "{$this->trash}/example.org/old-20260901000000/dirlink");

    expect(($this->helper)()->purge(30))->toBe(['purged' => 1, 'refused' => 0]);

    expect("{$this->trash}/example.org")->not->toBeDirectory()
        ->and(TempTree::snapshot("{$this->tmp}/outside"))->toBe(['keep' => 'file:outside']);
});

it('refuses a symlinked entry or domain directory', function (string $link) {
    mkdir("{$this->tmp}/outside/old-20260901000000", 0700);
    mkdir($this->trash, 0700);
    match ($link) {
        'entry' => (function () {
            mkdir("{$this->trash}/example.org", 0700);
            symlink("{$this->tmp}/outside/old-20260901000000", "{$this->trash}/example.org/old-20260901000000");
        })(),
        'domain' => symlink("{$this->tmp}/outside", "{$this->trash}/example.org"),
    };
    $before = TempTree::snapshot($this->trash);

    expect(($this->helper)()->purge(30))->toBe(['purged' => 0, 'refused' => 1]);

    expect(TempTree::snapshot($this->trash))->toBe($before)
        ->and(TempTree::snapshot("{$this->tmp}/outside"))->toBe(['keep' => 'file:outside', 'old-20260901000000' => 'dir'])
        ->and($this->log)->toHaveCount(1)
        ->and($this->log[0])->toStartWith('refused ')->toContain('symlink');
})->with(['entry', 'domain']);

it('refuses a file where an entry or domain directory should be', function () {
    mkdir("{$this->trash}/example.org", 0700, true);
    file_put_contents("{$this->trash}/example.org/old-20260901000000", 'x');
    file_put_contents("{$this->trash}/stray.org", 'x');
    $before = TempTree::snapshot($this->trash);

    expect(($this->helper)()->purge(30))->toBe(['purged' => 0, 'refused' => 2])
        ->and(TempTree::snapshot($this->trash))->toBe($before);
});

it('refuses a symlinked trash directory', function () {
    symlink("{$this->tmp}/outside", $this->trash);
    mkdir("{$this->tmp}/outside/example.org/old-20260901000000", 0700, true);

    expect(($this->helper)()->purge(30))->toBe(['purged' => 0, 'refused' => 1])
        ->and("{$this->tmp}/outside/example.org/old-20260901000000")->toBeDirectory();
});

it('leaves names without a timestamp alone', function () {
    foreach (['old', 'old-2026090100000', 'old-20260901000000.bak', 'old-20260231000000', 'old-20259999999999', 'old-20260901000000-x'] as $name) {
        TempTree::maildir("{$this->trash}/example.org/{$name}");
    }
    $before = TempTree::snapshot($this->trash);

    expect(($this->helper)()->purge(30))->toBe(['purged' => 0, 'refused' => 0])
        ->and(TempTree::snapshot($this->trash))->toBe($before)
        ->and($this->log)->toBe([]);
});

it('does nothing when there is no trash yet', function () {
    expect(($this->helper)()->purge(30))->toBe(['purged' => 0, 'refused' => 0])
        ->and($this->trash)->not->toBeDirectory();
});

it('refuses a retention below one day', function (int $days) {
    TempTree::maildir("{$this->trash}/example.org/new-20261010115959");

    expect(fn () => ($this->helper)()->purge($days))->toThrow(InvalidArgumentException::class)
        ->and("{$this->trash}/example.org/new-20261010115959")->toBeDirectory();
})->with([0, -1]);
