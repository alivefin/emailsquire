<?php

use Symfony\Component\Process\Process;
use Tests\Support\TempTree;

beforeEach(function () {
    $this->tmp = TempTree::create();
    $this->script = base_path('helpers/maildir.php');
    $this->runHelper = function (string ...$args): Process {
        $process = new Process([PHP_BINARY, $this->script, ...$args]);
        $process->run();

        return $process;
    };
});

afterEach(fn () => TempTree::remove($this->tmp));

it('exits 2 with usage on a bad command line', function (array $args) {
    // --socket points nowhere, so reaching the connect step would print "cannot connect" instead.
    $process = ($this->runHelper)(...$args, ...(in_array('--socket=/nonexistent', $args, true) ? [] : ['--socket=/nonexistent']));

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('usage: php maildir.php run|purge')
        ->not->toContain('cannot connect')
        ->and($process->getOutput())->toBe('');
})->with([
    'no mode' => [[]],
    'unknown mode' => [['shred']],
    'option before the mode' => [['--retention-days=30', 'purge']],
    'unsafe app schema' => [['run', '--app-schema=a;b']],
    'unsafe guide schema' => [['run', '--guide-schema=mail`server']],
    'unknown option' => [['run', '--mailroot=/var/vmail']],
    'option without a value' => [['run', '--mail-root']],
    'value in the next argument' => [['run', '--mail-root', '/var/vmail']],
    'repeated option' => [['run', '--mail-root=/a', '--mail-root=/b']],
    'relative mail root' => [['purge', '--mail-root=vmail']],
    'relative trash dir' => [['purge', '--trash-dir=trash']],
    'mail root is /' => [['purge', '--mail-root=/']],
    'relative socket' => [['purge', '--socket=mysqld.sock']],
    'socket with DSN parameters' => [['purge', '--socket=/run/mysqld/mysqld.sock;dbname=x']],
    'zero retention' => [['purge', '--retention-days=0']],
    'non-numeric retention' => [['purge', '--retention-days=30d']],
    'negative retention' => [['purge', '--retention-days=-1']],
]);

it('connects before purging and exits 2 when it cannot', function () {
    TempTree::maildir("{$this->tmp}/.emailsquire-trash/example.org/old-20200101000000");

    $process = ($this->runHelper)('purge', "--mail-root={$this->tmp}", '--socket=/nonexistent');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toStartWith('cannot connect')
        ->and("{$this->tmp}/.emailsquire-trash/example.org/old-20200101000000")->toBeDirectory();
});

it('skips quietly while another helper run holds the lock, and proceeds once it is released', function (string $mode) {
    $lockPath = "{$this->tmp}/.emailsquire-maildir.lock";
    $handle = fopen($lockPath, 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();
    TempTree::maildir("{$this->tmp}/.emailsquire-trash/example.org/old-20200101000000");

    $held = ($this->runHelper)($mode, "--mail-root={$this->tmp}", '--socket=/nonexistent');

    expect($held->getExitCode())->toBe(0)
        ->and($held->getOutput())->toBe('another maildir helper run is active; skipping'.PHP_EOL)
        ->and($held->getErrorOutput())->toBe('')
        ->and("{$this->tmp}/.emailsquire-trash/example.org/old-20200101000000")->toBeDirectory();

    flock($handle, LOCK_UN);
    fclose($handle);
    $free = ($this->runHelper)($mode, "--mail-root={$this->tmp}", '--socket=/nonexistent');

    expect($free->getExitCode())->toBe(2)
        ->and($free->getErrorOutput())->toStartWith('cannot connect')
        ->and(decoct(fileperms($lockPath) & 0o777))->toBe('600');
})->with(['run', 'purge']);

it('loads only its own files, never Composer or the framework', function () {
    $source = (string) file_get_contents($this->script);

    expect($source)->not->toContain('vendor')
        ->not->toContain('autoload')
        ->not->toContain('bootstrap')
        ->and(substr_count($source, "require_once __DIR__.'/maildir/"))->toBe(3)
        ->and(count(file($this->script)))->toBeLessThan(100);
});
