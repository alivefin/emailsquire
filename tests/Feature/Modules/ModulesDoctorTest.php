<?php

use App\Doctor\Checks\ModulesLoaded;
use App\Doctor\Status;
use Illuminate\Support\Facades\File;

function doctorPublicDir(): string
{
    $dir = sys_get_temp_dir().'/es-public-'.bin2hex(random_bytes(6));
    mkdir($dir.'/modules', 0777, true);
    app()->usePublicPath($dir);
    test()->afterEach(fn () => File::deleteDirectory($dir));

    return $dir;
}

function linkExample(string $public, ?string $target = null): void
{
    symlink($target ?? realpath(base_path(fixtureModule('example'))).'/public', $public.'/modules/example');
}

it('is ok when no modules are listed', function () {
    $result = app(ModulesLoaded::class)->run();

    expect($result->id)->toBe('modules.loaded')
        ->and($result->status)->toBe(Status::Ok)
        ->and($result->detail)->toBe('No modules listed');
});

it('is ok and lists a loaded module whose asset link is present', function () {
    bootWithModules(fixtureModule('example'));
    linkExample(doctorPublicDir());

    $result = app(ModulesLoaded::class)->run();

    expect($result->status)->toBe(Status::Ok)
        ->and($result->items)->toBe(['example 1.0.0']);
});

it('fails and lists each refused module', function () {
    bootWithModules(fixtureModule('bad-api'));
    doctorPublicDir();

    $result = app(ModulesLoaded::class)->run();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->items)->toContain(fixtureModule('bad-api').': core_api_unsupported');
});

it('fails when the ownership check is off outside testing, e2e and local', function () {
    bootWithModules(fixtureModule('example'));
    linkExample(doctorPublicDir());
    app()['env'] = 'production';
    config()->set('emailsquire.modules_require_root_owner', false);

    $result = app(ModulesLoaded::class)->run();

    expect($result->status)->toBe(Status::Fail)
        ->and($result->detail)->toContain('ownership check');
});

it('warns when the asset link is missing', function () {
    bootWithModules(fixtureModule('example'));
    doctorPublicDir();

    $result = app(ModulesLoaded::class)->run();

    expect($result->status)->toBe(Status::Warn)
        ->and($result->detail)->toContain('modules:link');
});

it('warns when the asset link points elsewhere', function () {
    bootWithModules(fixtureModule('example'));
    $public = doctorPublicDir();
    linkExample($public, sys_get_temp_dir());

    expect(app(ModulesLoaded::class)->run()->status)->toBe(Status::Warn);
});
