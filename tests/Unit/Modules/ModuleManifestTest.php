<?php

use App\Modules\ManifestInvalid;
use App\Modules\ModuleManifest;

function moduleDir(array|string|null $manifest): string
{
    $dir = sys_get_temp_dir().'/es-manifest-'.bin2hex(random_bytes(6));
    mkdir($dir);
    if ($manifest !== null) {
        file_put_contents($dir.'/module.json', is_string($manifest) ? $manifest : json_encode($manifest));
    }

    return $dir;
}

function validManifest(array $override = []): array
{
    return array_replace_recursive([
        'name' => 'server-info',
        'version' => '1.2.3',
        'core_api' => '^1.0',
        'namespace' => 'Acme\\ServerInfo\\',
        'provider' => 'Acme\\ServerInfo\\ServerInfoModule',
        'autoload' => 'src/autoload.php',
        'table_prefix' => 'srv_',
        'route' => '/server-info',
        'assets' => ['entry' => 'public/entry.js', 'style' => 'public/style.css'],
    ], $override);
}

function manifestFailure(string $dir): ?ManifestInvalid
{
    try {
        ModuleManifest::fromFile($dir);
    } catch (ManifestInvalid $e) {
        return $e;
    }

    return null;
}

function expectInvalid(array|string $manifest): void
{
    $e = manifestFailure(moduleDir($manifest));

    expect($e)->toBeInstanceOf(ManifestInvalid::class)
        ->and($e->reason)->toBe('manifest_invalid')
        ->and($e->getMessage())->not->toBe('');
}

it('reads a valid manifest', function () {
    $dir = moduleDir(validManifest());
    $m = ModuleManifest::fromFile($dir);

    expect($m->name)->toBe('server-info')
        ->and($m->version)->toBe('1.2.3')
        ->and($m->coreApi)->toBe('^1.0')
        ->and($m->namespace)->toBe('Acme\\ServerInfo\\')
        ->and($m->provider)->toBe('Acme\\ServerInfo\\ServerInfoModule')
        ->and($m->autoload)->toBe($dir.'/src/autoload.php')
        ->and($m->tablePrefix)->toBe('srv_')
        ->and($m->route)->toBe('/server-info')
        ->and($m->entry)->toBe('entry.js')
        ->and($m->style)->toBe('style.css')
        ->and($m->dir)->toBe($dir);
});

it('leaves entry and style null without assets', function () {
    $manifest = validManifest();
    unset($manifest['assets']);
    $m = ModuleManifest::fromFile(moduleDir($manifest));

    expect($m->entry)->toBeNull()->and($m->style)->toBeNull();
});

it('reports manifest_missing for a directory without module.json', function () {
    $e = manifestFailure(moduleDir(null));

    expect($e)->toBeInstanceOf(ManifestInvalid::class)->and($e->reason)->toBe('manifest_missing');
});

it('refuses a manifest missing a required field', function (string $field) {
    $manifest = validManifest();
    unset($manifest[$field]);
    expectInvalid($manifest);
})->with(['name', 'version', 'core_api', 'namespace', 'provider', 'autoload', 'table_prefix', 'route']);

it('refuses an invalid value', function (array $override) {
    expectInvalid(validManifest($override));
})->with([
    'name Server' => [['name' => 'Server']],
    'name 41 chars' => [['name' => 'a'.str_repeat('b', 40)]],
    'prefix without underscore' => [['table_prefix' => 'srv']],
    'route capital' => [['route' => '/Server']],
    'route nested' => [['route' => '/a/b']],
    'namespace App' => [['namespace' => 'App\\X\\']],
    'namespace no trailing backslash' => [['namespace' => 'Acme\\X']],
    'autoload parent' => [['autoload' => '../x.php']],
    'autoload absolute' => [['autoload' => '/etc/x.php']],
    'entry absolute' => [['assets' => ['entry' => '/abs.js']]],
    'entry outside public' => [['assets' => ['entry' => 'src/entry.js']]],
    'entry traversal' => [['assets' => ['entry' => 'public/../x.js']]],
    'core_api not a string' => [['core_api' => 1]],
]);

it('refuses invalid JSON', function () {
    expectInvalid('{not json');
});

it('refuses JSON that is not an object', function () {
    expectInvalid('[1,2]');
});
