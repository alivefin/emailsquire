<?php

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('root artisan overrides hostile cache paths and cleans its private cache after artisan exits', function (int $exit) {
    $script = base_path('scripts/root-artisan');
    expect(is_file($script))->toBeTrue();
    $root = sys_get_temp_dir().'/root-artisan-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($root.'/scripts', 0700, true);
    $files->makeDirectory($root.'/hostile', 0700);
    $marker = $root.'/marker';
    file_put_contents($root.'/hostile/config.php', '<?php file_put_contents('.var_export($marker, true).', "loaded"); return [];');

    // Exercise the shipped environment logic without root: change ONLY the EUID guard in this
    // throwaway release. The real non-root refusal is tested separately. No .env is bootstrapped.
    file_put_contents($root.'/scripts/root-artisan', str_replace(['EUID != 0', 'php_bin="/usr/bin/php"'], ['EUID != '.posix_geteuid(), 'php_bin='.escapeshellarg(PHP_BINARY)], file_get_contents($script)));
    $artisan = <<<'PHP'
<?php
require %s;
$app = new Illuminate\Foundation\Application(getcwd());
$paths = [$app->getCachedConfigPath(), $app->getCachedServicesPath(), $app->getCachedPackagesPath(), $app->getCachedRoutesPath(), $app->getCachedEventsPath()];
if (is_file($paths[0])) { require $paths[0]; }
$app->instance('path.storage', getcwd().'/storage');
$view = require %s;
echo json_encode(['cwd' => getcwd(), 'paths' => $paths, 'views' => $view['compiled'], 'mode' => fileperms(dirname($paths[0])) & 0777, 'uid' => fileowner(dirname($paths[0])), 'arguments' => array_slice($argv, 1), 'migrator' => getenv('DB_MIGRATOR_USERNAME'), 'phprc' => getenv('PHPRC')]);
exit(%d);
PHP;
    file_put_contents($root.'/artisan', sprintf($artisan, var_export(base_path('vendor/autoload.php'), true), var_export(base_path('vendor/laravel/framework/config/view.php'), true), $exit));
    $environment = array_fill_keys(['APP_CONFIG_CACHE', 'APP_SERVICES_CACHE', 'APP_PACKAGES_CACHE', 'APP_ROUTES_CACHE', 'APP_EVENTS_CACHE'], $root.'/hostile/config.php');
    $environment['VIEW_COMPILED_PATH'] = $root.'/hostile';
    // A hostile caller environment: PHP configuration that prepends code, and a BASH_ENV script.
    file_put_contents($root.'/hostile/prepend.php', '<?php file_put_contents('.var_export($root.'/prepend-marker', true).', "loaded");');
    file_put_contents($root.'/hostile/php.ini', 'auto_prepend_file='.$root.'/hostile/prepend.php'.PHP_EOL);
    file_put_contents($root.'/hostile/bash-env.sh', 'touch '.escapeshellarg($root.'/bash-env-marker').PHP_EOL);
    $hostile = ['PHPRC' => $root.'/hostile', 'PHP_INI_SCAN_DIR' => $root.'/hostile', 'BASH_ENV' => $root.'/hostile/bash-env.sh'];

    try {
        $normal = new Process([PHP_BINARY, 'artisan'], $root, $environment);
        $normal->run();
        expect(is_file($marker))->toBeTrue(); // Positive control: Laravel's normal override loads it.
        unlink($marker);

        $wrapped = new Process(['bash', '-p', $root.'/scripts/root-artisan', 'test:command', 'two words'], sys_get_temp_dir(), $environment + $hostile + ['DB_MIGRATOR_USERNAME' => 'admin']);
        $wrapped->run();
        expect($wrapped->getExitCode())->toBe($exit);
        $result = json_decode($wrapped->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        expect(is_file($marker))->toBeFalse()
            ->and(is_file($root.'/prepend-marker'))->toBeFalse()
            ->and(is_file($root.'/bash-env-marker'))->toBeFalse()
            ->and($result['migrator'])->toBe('admin')
            ->and($result['phprc'])->toBeFalse()
            ->and($result['cwd'])->toBe(realpath($root))
            ->and($result['mode'])->toBe(0700)
            ->and($result['uid'])->toBe(posix_geteuid())
            ->and($result['arguments'])->toBe(['test:command', 'two words']);
        $cache = dirname($result['paths'][0]);
        foreach ($result['paths'] as $path) {
            expect(dirname($path))->toBe($cache);
        }
        expect($result['views'])->toBe($cache.'/views')
            ->and(is_dir($cache))->toBeFalse();
    } finally {
        $files->deleteDirectory($root);
    }
})->with([0, 23]);

it('root artisan refuses an unprivileged caller', function () {
    $process = new Process(['bash', base_path('scripts/root-artisan'), 'list'], sys_get_temp_dir());
    $process->run();
    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('must run as root');
})->skip(fn () => posix_geteuid() === 0, 'requires an unprivileged test runner');

it('release verification requires the root artisan wrapper', function () {
    $root = sys_get_temp_dir().'/root-artisan-release-'.bin2hex(random_bytes(8));
    $files = new Filesystem;
    $files->makeDirectory($root.'/vendor', 0700, true);
    $files->makeDirectory($root.'/public/build', 0700, true);
    file_put_contents($root.'/vendor/autoload.php', '<?php');
    file_put_contents($root.'/public/build/manifest.json', '{}');
    try {
        (new Process(['tar', '-czf', $root.'/release.tar.gz', '-C', $root, 'vendor', 'public']))->mustRun();
        $process = new Process(['bash', base_path('scripts/verify-release.sh'), $root.'/release.tar.gz']);
        $process->run();
        expect($process->getExitCode())->toBe(1)
            ->and($process->getErrorOutput())->toContain('scripts/root-artisan is missing');
    } finally {
        $files->deleteDirectory($root);
    }
});
