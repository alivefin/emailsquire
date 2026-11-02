<?php

use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function commandPublicDir(): string
{
    $dir = sys_get_temp_dir().'/es-public-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    commandTempDirs($dir);

    return $dir;
}

/** @return list<string> the temporary directories made so far; with $add, records one more */
function commandTempDirs(?string $add = null, bool $reset = false): array
{
    static $dirs = [];
    if ($reset) {
        $dirs = [];
    }
    if ($add !== null) {
        $dirs[] = $add;
    }

    return $dirs;
}

// The fixture's public/ is the bundle `npm run build:fixture-module` writes (gitignored); Pest runs without it.
beforeEach(fn () => File::ensureDirectoryExists(base_path(fixtureModule('example')).'/public'));

afterEach(function () {
    foreach (commandTempDirs() as $dir) {
        @chmod($dir.'/modules', 0755);
        File::deleteDirectory($dir);
    }
    commandTempDirs(reset: true);
});

function exampleTarget(): string
{
    return realpath(base_path(fixtureModule('example'))).'/public';
}

it('checks modules: exit 0 when all load', function () {
    bootWithModules(fixtureModule('example'));

    expect(Artisan::call('emailsquire:modules:check'))->toBe(0)
        ->and(Artisan::output())->toContain('example');
});

it('checks modules: exit 1 when one is refused', function () {
    bootWithModules(fixtureModule('bad-api'));

    expect(Artisan::call('emailsquire:modules:check'))->toBe(1)
        ->and(Artisan::output())->toContain('bad-api: core_api_unsupported');
});

it('links a module\'s public directory, creating modules/', function () {
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();

    expect(Artisan::call('emailsquire:modules:link', ['--public' => $public]))->toBe(0)
        ->and(readlink($public.'/modules/example'))->toBe(exampleTarget());
});

it('replaces a stale link', function () {
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();
    mkdir($public.'/modules');
    symlink(sys_get_temp_dir(), $public.'/modules/example');

    expect(Artisan::call('emailsquire:modules:link', ['--public' => $public]))->toBe(0)
        ->and(readlink($public.'/modules/example'))->toBe(exampleTarget())
        ->and(glob($public.'/modules/.*-*'))->toBe([]);
});

it('removes a link for a module that is not listed, and leaves real files alone', function () {
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();
    mkdir($public.'/modules');
    symlink(sys_get_temp_dir(), $public.'/modules/gone');
    mkdir($public.'/modules/other');

    expect(Artisan::call('emailsquire:modules:link', ['--public' => $public]))->toBe(0)
        ->and(is_link($public.'/modules/gone'))->toBeFalse()
        ->and(is_dir($public.'/modules/other'))->toBeTrue();
});

it('refuses to replace a real directory', function () {
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();
    mkdir($public.'/modules/example', 0777, true);

    expect(Artisan::call('emailsquire:modules:link', ['--public' => $public]))->toBe(1)
        ->and(Artisan::output())->toContain($public.'/modules/example')
        ->and(is_dir($public.'/modules/example') && ! is_link($public.'/modules/example'))->toBeTrue();
});

it('skips a module without a public directory, leaving the committed fixture alone', function () {
    $root = commandPublicDir();
    File::copyDirectory(base_path(fixtureModule('example')), $root.'/example');
    File::deleteDirectory($root.'/example/public');
    config(['emailsquire.modules_root' => $root]);
    app()->instance(ModuleRegistry::class, ModuleLoader::load([$root.'/example']));
    $public = commandPublicDir();

    $code = Artisan::call('emailsquire:modules:link', ['--public' => $public]);

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('no public directory')
        ->and(is_link($public.'/modules/example'))->toBeFalse()
        ->and(is_dir(base_path(fixtureModule('example')).'/public'))->toBeTrue();
});

it('links the loaded modules, then exits 1 naming each refused one', function () {
    bootWithModules(fixtureModule('example').','.fixtureModule('bad-api'));
    $public = commandPublicDir();
    mkdir($public.'/modules');
    symlink(sys_get_temp_dir(), $public.'/modules/bad-api');

    $code = Artisan::call('emailsquire:modules:link', ['--public' => $public]);

    expect($code)->toBe(1)
        ->and(readlink($public.'/modules/example'))->toBe(exampleTarget())
        ->and(is_link($public.'/modules/bad-api'))->toBeFalse()
        ->and(Artisan::output())->toContain(fixtureModule('bad-api').': core_api_unsupported');
});

it('refuses a modules directory that is a symlink and changes nothing', function () {
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();
    $elsewhere = commandPublicDir();
    symlink($elsewhere, $public.'/modules');

    $code = Artisan::call('emailsquire:modules:link', ['--public' => $public]);

    expect($code)->toBe(1)
        ->and(Artisan::output())->toContain('not a plain directory')
        ->and(scandir($elsewhere))->toBe(['.', '..'])
        ->and(readlink($public.'/modules'))->toBe($elsewhere);
});

it('removes a temporary link left behind by an earlier run', function () {
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();
    mkdir($public.'/modules');
    symlink(sys_get_temp_dir(), $public.'/modules/.example-0123456789ab');
    symlink(sys_get_temp_dir(), $public.'/modules/.keep-me');

    expect(Artisan::call('emailsquire:modules:link', ['--public' => $public]))->toBe(0)
        ->and(is_link($public.'/modules/.example-0123456789ab'))->toBeFalse()
        ->and(is_link($public.'/modules/.keep-me'))->toBeTrue();
});

it('fails with one line and leaves no temporary link when the link cannot be made', function () {
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $this->markTestSkipped('root ignores directory permissions');
    }
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();
    mkdir($public.'/modules');
    chmod($public.'/modules', 0555);

    $code = Artisan::call('emailsquire:modules:link', ['--public' => $public]);
    $output = trim(Artisan::output());

    expect($code)->toBe(1)
        ->and($output)->toStartWith('Cannot link')
        ->and($output)->not->toContain("\n")
        ->and(scandir($public.'/modules'))->toBe(['.', '..']);
});
