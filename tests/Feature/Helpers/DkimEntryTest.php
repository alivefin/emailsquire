<?php

use Symfony\Component\Process\Process;
use Tests\Support\TempTree;

beforeEach(function () {
    $this->tmp = realpath(TempTree::create());
    $this->script = base_path('helpers/dkim.php');
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
        ->and($process->getErrorOutput())->toContain('usage: php dkim.php run')
        ->not->toContain('cannot connect')
        ->and($process->getOutput())->toBe('');
})->with([
    'no mode' => [[]],
    'unknown mode' => [['purge']],
    'option before the mode' => [['--dkim-dir=/tmp', 'run']],
    'unsafe app schema' => [['run', '--app-schema=a;b']],
    'unsafe guide schema' => [['run', '--guide-schema=mail`server']],
    'unknown option' => [['run', '--dkimdir=/var/lib/rspamd/dkim']],
    'option without a value' => [['run', '--dkim-dir']],
    'value in the next argument' => [['run', '--dkim-dir', '/var/lib/rspamd/dkim']],
    'repeated option' => [['run', '--dkim-dir=/a', '--dkim-dir=/b']],
    'relative dkim dir' => [['run', '--dkim-dir=dkim']],
    'dkim dir is /' => [['run', '--dkim-dir=/']],
    'relative selector map' => [['run', '--selector-map=dkim_selectors.map']],
    'selector map is a directory path' => [['run', '--selector-map=/etc/rspamd/']],
    'selector map is a dot entry' => [['run', '--selector-map=/etc/rspamd/..']],
    'relative socket' => [['run', '--socket=mysqld.sock']],
    'socket with DSN parameters' => [['run', '--socket=/run/mysqld/mysqld.sock;dbname=x']],
]);

it('runs as the owner of the DKIM directory: the guard lets the owner through to the connect step', function () {
    $process = ($this->runHelper)('run', "--dkim-dir={$this->tmp}", '--socket=/nonexistent');

    // The helper creates the lock under umask 0077, so it is 0600 from the start.
    expect(fileowner($this->tmp))->toBe(posix_geteuid())
        ->and($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toStartWith('cannot connect')
        ->and(decoct(fileperms("{$this->tmp}/.emailsquire-dkim.lock") & 0o777))->toBe('600');
});

it('refuses to run as anyone but the owner of the DKIM directory, before it locks or connects', function () {
    $rootOwned = '/tmp';
    if (posix_geteuid() === 0 || fileowner($rootOwned) === posix_geteuid()) {
        $this->markTestSkipped('needs a directory that the test user does not own');
    }
    $lock = "{$rootOwned}/.emailsquire-dkim.lock";
    $lockExisted = file_exists($lock);

    try {
        $process = ($this->runHelper)('run', "--dkim-dir={$rootOwned}", '--socket=/nonexistent');
        $owner = posix_getpwuid((int) fileowner($rootOwned))['name'];

        expect($process->getExitCode())->toBe(2)
            ->and($process->getErrorOutput())->toContain("as {$owner}, the owner of {$rootOwned}")
            ->toContain("runuser -u {$owner} -- php ")
            ->not->toContain('cannot connect')
            ->and($process->getOutput())->toBe('')
            ->and(file_exists($lock))->toBe($lockExisted);
    } finally {
        if (! $lockExisted && is_file($lock) && fileowner($lock) === posix_geteuid()) {
            unlink($lock);
        }
    }
});

it('exits 2 when the DKIM directory is missing', function () {
    $process = ($this->runHelper)('run', "--dkim-dir={$this->tmp}/missing", '--socket=/nonexistent');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('cannot read the owner of the DKIM directory');
});

it('skips quietly while another helper run holds the lock', function () {
    $handle = fopen("{$this->tmp}/.emailsquire-dkim.lock", 'c');
    expect(flock($handle, LOCK_EX | LOCK_NB))->toBeTrue();

    $held = ($this->runHelper)('run', "--dkim-dir={$this->tmp}", '--socket=/nonexistent');

    expect($held->getExitCode())->toBe(0)
        ->and($held->getOutput())->toBe('another dkim helper run is active; skipping'.PHP_EOL)
        ->and($held->getErrorOutput())->toBe('');
    flock($handle, LOCK_UN);
    fclose($handle);
});

it('refuses a lock file that is a symlink, and leaves its target alone', function () {
    $target = "{$this->tmp}/elsewhere";
    file_put_contents($target, 'keep');
    chmod($target, 0644);
    symlink($target, "{$this->tmp}/.emailsquire-dkim.lock");

    $process = ($this->runHelper)('run', "--dkim-dir={$this->tmp}", '--socket=/nonexistent');

    expect($process->getExitCode())->toBe(2)
        ->and($process->getErrorOutput())->toContain('lock file')->toContain('symlink')
        ->not->toContain('cannot connect')
        ->and(file_get_contents($target))->toBe('keep')
        ->and(decoct(fileperms($target) & 0o777))->toBe('644');
});

it('loads only its own files, never Composer, the framework or the maildir helper', function () {
    $source = (string) file_get_contents($this->script);
    $classes = implode("\n", array_map(file_get_contents(...), glob(base_path('helpers/dkim/*.php'))));

    expect($source)->not->toContain('vendor')
        ->not->toContain('autoload')
        ->not->toContain('bootstrap')
        ->not->toContain('maildir')
        ->and(preg_match_all("~require_once __DIR__\\.'/dkim/[A-Za-z]+\\.php';~", $source))->toBe(count(glob(base_path('helpers/dkim/*.php'))))
        ->and(count(file($this->script)))->toBeLessThan(100)
        ->and($classes)->not->toMatch('/\\b(?:require|include)(?:_once)?\\b\\s*[(\'"]/');
});
