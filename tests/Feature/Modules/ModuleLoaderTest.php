<?php

use App\Modules\LoadedModule;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRefusal;
use App\Modules\ModuleRegistry;
use App\Modules\PathOwner;
use EmailSquire\Modules\Example\ExampleCheck;
use EmailSquire\Modules\Example\ExampleServiceProvider;
use Illuminate\Support\Facades\File;

/** A fresh temporary directory, removed after the test. */
function loaderTempDir(): string
{
    $dir = sys_get_temp_dir().'/es-modules-'.bin2hex(random_bytes(6));
    mkdir($dir);
    loaderTempDirs($dir);

    return $dir;
}

/** @return list<string> the temporary directories made so far; with $add, records one more */
function loaderTempDirs(?string $add = null, bool $reset = false): array
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

/** A copy of a fixture module at $dir, with $manifest merged into its manifest. */
function copyFixtureModule(string $name, string $dir, array $manifest = []): string
{
    File::copyDirectory(base_path(fixtureModule($name)), $dir);
    if ($manifest !== []) {
        $file = $dir.'/emailsquire-module.json';
        $data = array_replace(json_decode((string) file_get_contents($file), true), $manifest);
        file_put_contents($file, json_encode($data));
    }

    return $dir;
}

/** Lists $paths in emailsquire.modules and loads them, as AppServiceProvider does. */
function loadModules(string ...$paths): ModuleRegistry
{
    config(['emailsquire.modules' => $paths]);

    return ModuleLoader::load(config()->array('emailsquire.modules'));
}

/** @return list<string> */
function refusalReasons(ModuleRegistry $registry): array
{
    return array_map(fn (ModuleRefusal $r) => $r->reason, $registry->refused());
}

function onlyRefusal(ModuleRegistry $registry): ModuleRefusal
{
    expect($registry->loaded())->toBe([])->and($registry->refused())->toHaveCount(1);

    return $registry->refused()[0];
}

beforeEach(function () {
    config([
        'emailsquire.modules_root' => 'tests/fixtures/modules',
        'emailsquire.modules_require_root_owner' => false,
    ]);
});

afterEach(function () {
    foreach (loaderTempDirs() as $dir) {
        File::deleteDirectory($dir);
    }
    loaderTempDirs(reset: true);
});

it('loads the example module and registers its provider', function () {
    $registry = loadModules(fixtureModule('example'));

    expect($registry->refused())->toBe([])
        ->and($registry->loaded())->toHaveCount(1)
        ->and($registry->loaded()[0])->toBeInstanceOf(LoadedModule::class)
        ->and($registry->loaded()[0]->manifest->name)->toBe('example')
        ->and($registry->loaded()[0]->provider)->toBeInstanceOf(ExampleServiceProvider::class)
        ->and($registry->find('example'))->toBe($registry->loaded()[0])
        ->and($registry->find('other'))->toBeNull()
        ->and(app()->getProviders(ExampleServiceProvider::class))->not->toBeEmpty();
});

it('loads an absolute path the same way', function () {
    $registry = loadModules(base_path(fixtureModule('example')));

    expect($registry->refused())->toBe([])->and($registry->find('example'))->not->toBeNull();
});

it('collects the loaded modules\' doctor checks and audit groups', function () {
    $registry = loadModules(fixtureModule('example'));

    expect($registry->doctorChecks())->toBe([ExampleCheck::class])->and($registry->auditGroups())->toBe([]);
});

it('refuses a path that is not a directory', function (string $path) {
    $refusal = onlyRefusal(loadModules($path));

    expect($refusal->reason)->toBe('not_directory')
        ->and($refusal->path)->toBe($path)
        ->and($refusal->name)->toBeNull()
        ->and($refusal->detail)->not->toBe('');
})->with([
    'missing' => fixtureModule('nonexistent'),
    'a file' => fixtureModule('example/emailsquire-module.json'),
]);

it('refuses a directory outside the modules root', function () {
    $outside = copyFixtureModule('example', loaderTempDir().'/example');

    expect(onlyRefusal(loadModules($outside))->reason)->toBe('outside_root');
});

it('refuses a link in the modules root that points outside it', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $outside = copyFixtureModule('example', loaderTempDir().'/example');
    symlink($outside, $root.'/linked');

    expect(onlyRefusal(loadModules($root.'/linked'))->reason)->toBe('outside_root');
});

it('refuses the modules root itself', function () {
    expect(onlyRefusal(loadModules('tests/fixtures/modules'))->reason)->toBe('outside_root');
});

it('refuses every module when the modules root does not exist', function () {
    config(['emailsquire.modules_root' => '/nonexistent/emailsquire-modules']);

    expect(onlyRefusal(loadModules(fixtureModule('example')))->reason)->toBe('outside_root');
});

it('refuses an autoloader that links outside the module', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example');
    $elsewhere = loaderTempDir().'/autoload.php';
    rename($module.'/autoload.php', $elsewhere);
    symlink($elsewhere, $module.'/autoload.php');

    $refusal = onlyRefusal(loadModules($module));

    expect($refusal->reason)->toBe('outside_root')->and($refusal->detail)->toContain('autoload.php');
});

it('refuses an asset that links outside the module', function (string $asset) {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example', ['assets' => [$asset => "public/{$asset}.txt"]]);
    $elsewhere = loaderTempDir().'/asset.txt';
    file_put_contents($elsewhere, 'x');
    mkdir($module.'/public');
    symlink($elsewhere, "{$module}/public/{$asset}.txt");

    expect(onlyRefusal(loadModules($module))->reason)->toBe('outside_root');
})->with(['entry', 'style']);

it('refuses any link in the module that points outside it, whether or not ownership is checked', function (bool $enforced) {
    config(['emailsquire.modules_require_root_owner' => $enforced]);
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example');
    symlink(loaderTempDir(), $module.'/src/elsewhere');

    $refusal = onlyRefusal(loadModules($module));

    expect($refusal->reason)->toBe('outside_root')->and($refusal->detail)->toContain('src/elsewhere');
})->with(['not enforced' => false, 'enforced' => true]);

it('refuses a public directory that links outside the module', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example', ['assets' => ['entry' => 'public/entry.js']]);
    $elsewhere = loaderTempDir();
    file_put_contents($elsewhere.'/entry.js', 'x');
    symlink($elsewhere, $module.'/public');

    expect(onlyRefusal(loadModules($module))->reason)->toBe('outside_root');
});

it('refuses a link that leads nowhere', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example');
    symlink($module.'/missing', $module.'/dangling');

    expect(onlyRefusal(loadModules($module))->reason)->toBe('outside_root');
});

it('accepts a link that stays inside the module', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example');
    symlink($module.'/src', $module.'/source');

    expect(loadModules($module)->refused())->toBe([]);
});

it('accepts assets that are not built yet', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example', ['assets' => ['entry' => 'public/entry.js', 'style' => 'public/entry.css']]);

    expect(loadModules($module)->refused())->toBe([]);
});

it('refuses a core_api the core does not satisfy', function () {
    $refusal = onlyRefusal(loadModules(fixtureModule('bad-api')));

    expect($refusal->reason)->toBe('core_api_unsupported')->and($refusal->name)->toBe('bad-api');
});

it('refuses a directory without a manifest', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    mkdir($root.'/empty');

    expect(onlyRefusal(loadModules($root.'/empty'))->reason)->toBe('manifest_missing');
});

it('refuses an invalid manifest', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example', ['name' => 'Example']);

    expect(onlyRefusal(loadModules($module))->reason)->toBe('manifest_invalid');
});

it('refuses a module whose autoloader is missing', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example');
    unlink($module.'/autoload.php');

    expect(onlyRefusal(loadModules($module))->reason)->toBe('autoload_missing');
});

it('refuses a provider class that does not exist', function () {
    $refusal = onlyRefusal(loadModules(fixtureModule('no-provider')));

    expect($refusal->reason)->toBe('provider_missing')->and($refusal->name)->toBe('no-provider');
});

it('refuses a provider that does not implement Module', function () {
    expect(onlyRefusal(loadModules(fixtureModule('not-module')))->reason)->toBe('provider_not_module');
});

it('refuses a module listed twice the second time', function () {
    $registry = loadModules(fixtureModule('example'), fixtureModule('example'));

    expect($registry->loaded())->toHaveCount(1)
        ->and(refusalReasons($registry))->toBe(['duplicate_name'])
        ->and($registry->refused()[0]->name)->toBe('example');
});

it('refuses a provider outside the manifest\'s namespace', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = copyFixtureModule('example', $root.'/example', ['namespace' => 'EmailSquire\\Modules\\Other\\']);

    $refusal = onlyRefusal(loadModules($module));

    expect($refusal->reason)->toBe('provider_not_module')->and($refusal->detail)->toContain('namespace');
});

it('refuses a provider another module has already registered', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $first = copyFixtureModule('example', $root.'/first');
    $second = copyFixtureModule('example', $root.'/second', ['name' => 'example-two', 'route' => '/example-two']);

    $registry = loadModules($first, $second);

    expect($registry->loaded())->toHaveCount(1)
        ->and(refusalReasons($registry))->toBe(['provider_not_module'])
        ->and($registry->refused()[0]->detail)->toContain('already registered')
        ->and(app()->getProviders(ExampleServiceProvider::class))->toHaveCount(1);
});

it('refuses a module whose route another module has claimed', function () {
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $first = copyFixtureModule('example', $root.'/first');
    $second = copyFixtureModule('example', $root.'/second', ['name' => 'example-two']);

    $registry = loadModules($first, $second);

    expect($registry->loaded())->toHaveCount(1)
        ->and(refusalReasons($registry))->toBe(['route_clash'])
        ->and($registry->refused()[0]->name)->toBe('example-two');
});

it('refuses a provider whose registration throws, and the app still answers', function () {
    $refusal = onlyRefusal(loadModules(fixtureModule('throws')));

    expect($refusal->reason)->toBe('provider_failed')
        ->and($refusal->detail)->toContain('throws fixture');
    $this->get('/up')->assertOk();
});

it('refuses a module the test user owns when root ownership is required', function () {
    config(['emailsquire.modules_require_root_owner' => true]);

    expect(onlyRefusal(loadModules(fixtureModule('example')))->reason)->toBe('not_root_owned');
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'runs as root');

it('names the first file inside the module that root does not own', function () {
    config(['emailsquire.modules_require_root_owner' => true]);
    $root = loaderTempDir();
    config(['emailsquire.modules_root' => $root]);
    $module = realpath(copyFixtureModule('example', $root.'/example'));

    $refusal = onlyRefusal(loadModules($module));

    expect($refusal->reason)->toBe('not_root_owned')->and($refusal->detail)->toContain($module.'/');
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'runs as root');

it('enforces root ownership outside testing, e2e and local whatever the setting', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(onlyRefusal(loadModules(fixtureModule('example')))->reason)->toBe('not_root_owned');
})->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'runs as root');

it('waives root ownership when the setting is off in a development environment', function (string $env) {
    app()->detectEnvironment(fn () => $env);

    expect(ModuleLoader::ownershipEnforced())->toBeFalse()
        ->and(loadModules(fixtureModule('example'))->refused())->toBe([]);
})->with(['testing', 'e2e', 'local']);

it('keeps root ownership on in a development environment when the setting is on', function (string $env) {
    app()->detectEnvironment(fn () => $env);
    config(['emailsquire.modules_require_root_owner' => true]);

    expect(ModuleLoader::ownershipEnforced())->toBeTrue();
})->with(['testing', 'e2e', 'local']);

it('never throws, and records every refusal beside the modules it loads', function () {
    $registry = loadModules(
        fixtureModule('nonexistent'),
        fixtureModule('bad-api'),
        fixtureModule('example'),
        fixtureModule('no-provider'),
        fixtureModule('not-module'),
        fixtureModule('throws'),
        fixtureModule('example'),
        copyFixtureModule('example', loaderTempDir().'/example'),
    );

    expect(array_map(fn (LoadedModule $m) => $m->manifest->name, $registry->loaded()))->toBe(['example'])
        ->and(refusalReasons($registry))->toBe([
            'not_directory', 'core_api_unsupported', 'provider_missing', 'provider_not_module',
            'provider_failed', 'duplicate_name', 'outside_root',
        ]);
});

it('loads nothing when no module is listed', function () {
    $registry = app(ModuleRegistry::class);

    expect($registry->loaded())->toBe([])->and($registry->refused())->toBe([]);
});

it('registers a listed module while the application boots', function () {
    bootWithModules(fixtureModule('example'));

    expect(app()->getProviders(ExampleServiceProvider::class))->toHaveCount(1)
        ->and(array_map(fn (LoadedModule $m) => $m->manifest->name, app(ModuleRegistry::class)->loaded()))->toBe(['example'])
        ->and(app(ModuleRegistry::class)->refused())->toBe([]);
});

it('boots and answers when a listed module fails to register', function () {
    bootWithModules(fixtureModule('example').','.fixtureModule('throws'));

    expect(array_map(fn (LoadedModule $m) => $m->manifest->name, app(ModuleRegistry::class)->loaded()))->toBe(['example'])
        ->and(refusalReasons(app(ModuleRegistry::class)))->toBe(['provider_failed']);
    $this->get('/up')->assertOk();
});

it('boots with the modules list restored afterwards', function () {
    expect(config('emailsquire.modules'))->toBe([])->and(app(ModuleRegistry::class)->loaded())->toBe([]);
});

describe('PathOwner', function () {
    it('accepts root-owned directories that only root can write, up to /', function () {
        expect(PathOwner::isRootOwnedUpTo('/usr/bin', '/usr'))->toBeTrue();
    })->skip(fn () => ! is_dir('/usr/bin') || fileowner('/usr/bin') !== 0 || fileowner('/usr') !== 0 || fileowner('/') !== 0, 'no root-owned /usr/bin');

    it('refuses a directory the test user owns', function () {
        expect(PathOwner::isRootOwnedUpTo(base_path('tests/fixtures/modules/example'), base_path('tests/fixtures/modules')))->toBeFalse();
    })->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'runs as root');

    it('refuses a root-owned directory that others may write, even a sticky one', function () {
        $tmp = (string) realpath('/tmp');

        expect(PathOwner::isRootOwnedUpTo($tmp, $tmp))->toBeFalse();
    })->skip(fn () => ! is_dir('/tmp') || fileowner((string) realpath('/tmp')) !== 0 || (fileperms((string) realpath('/tmp')) & 0o1777) !== 0o1777, 'no root-owned 1777 /tmp');

    it('refuses a path outside the root', function () {
        expect(PathOwner::isRootOwnedUpTo('/usr/bin', '/etc'))->toBeFalse();
    });

    it('refuses a missing path', function () {
        expect(PathOwner::isRootOwnedUpTo('/usr/nonexistent-emailsquire', '/usr'))->toBeFalse();
    });

    it('names the first entry in a tree that root does not own', function () {
        $dir = (string) realpath(loaderTempDir());
        mkdir($dir.'/src');
        file_put_contents($dir.'/src/a.php', '<?php');

        expect(PathOwner::firstNotRootOwned($dir))->toBe($dir.'/src');
    })->skip(fn () => function_exists('posix_geteuid') && posix_geteuid() === 0, 'runs as root');

    it('finds nothing to refuse in an empty tree', function () {
        $dir = (string) realpath(loaderTempDir());

        expect(PathOwner::firstNotRootOwned($dir))->toBeNull()->and(PathOwner::firstEscapingLink($dir))->toBeNull();
    });

    it('names a nested link that leaves the tree, and ignores links that stay in it', function () {
        $dir = (string) realpath(loaderTempDir());
        mkdir($dir.'/a/b', recursive: true);
        symlink($dir.'/a', $dir.'/inside');
        symlink('..', $dir.'/a/up');
        symlink(sys_get_temp_dir(), $dir.'/a/b/out');

        expect(PathOwner::firstEscapingLink($dir))->toBe($dir.'/a/b/out');
    });
});
