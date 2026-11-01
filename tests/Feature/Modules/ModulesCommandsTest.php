<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

function commandPublicDir(): string
{
    $dir = sys_get_temp_dir().'/es-public-'.bin2hex(random_bytes(6));
    mkdir($dir, 0777, true);
    test()->afterEach(fn () => File::deleteDirectory($dir));

    return $dir;
}

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

it('skips a module without a public directory', function () {
    bootWithModules(fixtureModule('example'));
    $public = commandPublicDir();
    rename(exampleTarget(), exampleTarget().'.off');
    try {
        $code = Artisan::call('emailsquire:modules:link', ['--public' => $public]);
    } finally {
        rename(exampleTarget().'.off', exampleTarget());
    }

    expect($code)->toBe(0)
        ->and(Artisan::output())->toContain('no public directory')
        ->and(is_link($public.'/modules/example'))->toBeFalse();
});
