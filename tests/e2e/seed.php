<?php

/*
 * Resets the Playwright database: the app schema `emailsquire_e2e` (migrate:fresh), the guide schema
 * `mailserver_e2e` (the trixie fixture) and three server admins: two with confirmed two-factor (fixed
 * secrets that support.ts turns into codes), the second with `locale = 'fi'`, and one without
 * two-factor, whom `EMAILSQUIRE_REQUIRE_TWO_FACTOR=admins` sends to set it up. Run by the Playwright
 * global setup and again by each spec's beforeAll. It refuses to run unless .env.e2e is the loaded
 * configuration, so it can never touch the development or pest databases.
 */

declare(strict_types=1);

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use Tests\Support\GuideFixture;

const E2E_DATABASE = 'emailsquire_e2e';
const E2E_GUIDE_SCHEMA = 'mailserver_e2e';

/** The fixed TOTP secrets of the two admins with two-factor; support.ts holds the same values. */
const ADMIN_TOTP_SECRET = 'JBSWY3DPEHPK3PXP';
const FI_ADMIN_TOTP_SECRET = 'GEZDGNBVGY3TQOJQ';

/** @return array<string, mixed> confirmed two-factor with `$secret` and 8 fresh recovery codes */
function twoFactor(string $secret): array
{
    return [
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt($secret),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(
            (string) json_encode(Collection::times(8, fn () => RecoveryCode::generate())->all()),
        ),
        'two_factor_confirmed_at' => now(),
    ];
}

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

    // Also empties the cache table, which resets the rate limiters (sign-in, two-factor, step-up,
    // forgotten password) and the used two-factor codes between specs.
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
        ...twoFactor(ADMIN_TOTP_SECRET),
    ])->save();

    // A second admin who chose Finnish: signing in as them exercises the server-side language choice.
    $fiAdmin = new User;
    $fiAdmin->forceFill([
        'name' => 'Ylläpitäjä',
        'email' => 'fi-admin@example.org',
        'password' => 'Summersun-e2e-2026!',
        'password_set_at' => now(),
        'is_server_admin' => true,
        'locale' => 'fi',
        ...twoFactor(FI_ADMIN_TOTP_SECRET),
    ])->save();

    // An admin without two-factor: required for admins, so signing in leads to setting it up.
    $nofactorAdmin = new User;
    $nofactorAdmin->forceFill([
        'name' => 'New Admin',
        'email' => 'nofactor-admin@example.org',
        'password' => 'Summersun-e2e-2026!',
        'password_set_at' => now(),
        'is_server_admin' => true,
    ])->save();
} catch (Throwable $e) {
    fail($e::class.': '.$e->getMessage());
}

echo "seeded\n";
