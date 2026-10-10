<?php

/*
 * Resets the Playwright database: the app schema `emailsquire_e2e` (migrate:fresh), the guide schema
 * `mailserver_e2e` (the trixie fixture) and one server admin. Run by the Playwright global setup and
 * again by each spec's beforeAll. It refuses to run unless .env.e2e is the loaded configuration, so it
 * can never touch the development or pest databases.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

const E2E_DATABASE = 'emailsquire_e2e';
const E2E_GUIDE_SCHEMA = 'mailserver_e2e';

function fail(string $message): never
{
    fwrite(STDERR, "seed: {$message}\n");
    exit(1);
}

putenv('APP_ENV=e2e');
$_ENV['APP_ENV'] = 'e2e';
$_SERVER['APP_ENV'] = 'e2e';

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

try {
    $connection = config('database.default');
    $database = config("database.connections.{$connection}.database");

    if (app()->environment() !== 'e2e' || $database !== E2E_DATABASE || config('emailsquire.guide_schema') !== E2E_GUIDE_SCHEMA) {
        fail('refusing to run: .env.e2e is not the loaded configuration (is the config cached?).');
    }

    // The app connection names a database that may not exist yet, so create it over a bare connection.
    $c = config("database.connections.{$connection}");
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%s', $c['host'], $c['port']),
        $c['username'],
        $c['password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
    );
    $pdo->exec('CREATE DATABASE IF NOT EXISTS `'.E2E_DATABASE.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $pdo = null;
    DB::purge($connection);

    // Also empties the cache table, which resets the five-a-minute login limiter between specs.
    if (Artisan::call('migrate:fresh', ['--force' => true]) !== 0) {
        fail("migrate:fresh failed:\n".Artisan::output());
    }

    GuideFixture::load('trixie');

    $admin = new User;
    $admin->forceFill([
        'name' => 'Admin',
        'email' => 'admin@example.org',
        'password' => 'Summersun-e2e-2026!',
        'password_set_at' => now(),
        'is_server_admin' => true,
    ])->save();
} catch (Throwable $e) {
    fail($e::class.': '.$e->getMessage());
}

echo "seeded\n";
