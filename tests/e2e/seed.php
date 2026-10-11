<?php

/*
 * Resets the Playwright database: the app schema `emailsquire_e2e` (migrate:fresh), the guide schema
 * `mailserver_e2e` (the trixie fixture plus a second domain, example.net), three server admins and one
 * pending invitation. Every mailbox's password is `summersun`; `owner@example.org` is the portal's
 * mailbox owner, and example.org lets its owners forward (internal only) and manage aliases. Two admins
 * have confirmed two-factor (fixed secrets that support.ts turns into codes), the second with
 * `locale = 'fi'`; the third has none, so `EMAILSQUIRE_REQUIRE_TWO_FACTOR=admins` sends them to set it
 * up. Run by the Playwright global setup and again by each spec's beforeAll. It refuses to run unless
 * .env.e2e is the loaded configuration, so it can never touch the development or pest databases.
 */

declare(strict_types=1);

use App\MailServer\GuideSchema;
use App\Models\DomainDkim;
use App\Models\DomainSetting;
use App\Models\Invitation;
use App\Models\MailboxUsage;
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

/**
 * The link token of a pending invitation (`pending@example.org`, viewer of example.org) that the layout
 * checks open at /invite; support.ts holds the same value. Only its SHA-256 is stored, as the app does.
 */
const PENDING_INVITATION_TOKEN = 'layout-e2e-invitation-token-0123456789abcde';

/**
 * The fixture's mailbox hash (password `summersun`), reused for every other mailbox. The e2e stand-in for
 * Dovecot (FakeDovecotAuthClient) checks only `{BLF-CRYPT}`, so mary's `{CRYPT}` row gets it too.
 */
/** example.org's active DKIM public key, as tests/e2e/fixtures/dns.json publishes it. */
const E2E_DKIM_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0yCkY7BL2Z3hPxWvukB+xAdquwlRky8PJMoBIeN82eAIpk8pj1cMiUMhn1LBAWViJpmVIsfDbdR3I1wDqJLdHUa+3D6JMoR7xqIZmt9NiXyZFKbrH40Py8ZYXT1Rq5wOd0ppyCxwZ3qWVfjF87UX19WwPDC05iPVUAiS3MUeWXrRCgLh/sqKFH9o7K5H+QCeQMyC6TeGARvU96GL+CX+oAe6VQdt8x9miPsrFU8AIhe6rEszHlXPS7HoRFPgWc1nzeBaewu/gp18dO90fX0D4i0HSQZe3ZOHR/rHCNwo1LcFJ9V09plbQcu+rEBTyzpX1NSXrHACLa4rKr2oMtXzRQIDAQAB';

const MAILBOX_PASSWORD_HASH = '{BLF-CRYPT}$2y$05$7rFPPTUz0YcSkwcpz7tRoOMf54qNHPr2uGY18qW5rAnuIRpgE3e6O';

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

    // A second domain beside the fixture's example.org, so a manager or viewer of example.org has
    // something they must not see (sharing.spec, users.spec). Rows only, no DDL.
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'example.net']);
    DB::table(GuideSchema::table('virtual_users'))->insert([
        ['domain_id' => 11, 'email' => 'alice@example.net', 'password' => MAILBOX_PASSWORD_HASH, 'quota' => 0],
        ['domain_id' => 11, 'email' => 'bob@example.net', 'password' => MAILBOX_PASSWORD_HASH, 'quota' => 0],
    ]);

    // The mailbox portal (portal.spec): every example.org mailbox signs in with `summersun`, and
    // owner@example.org is the owner the specs sign in as, with 1.2 GB of its 5 GB default in use.
    DB::table(GuideSchema::table('virtual_users'))->where('email', 'mary@example.org')->update(['password' => MAILBOX_PASSWORD_HASH]);
    DB::table(GuideSchema::table('virtual_users'))->insert(
        ['domain_id' => 10, 'email' => 'owner@example.org', 'password' => MAILBOX_PASSWORD_HASH, 'quota' => 0],
    );
    $usage = new MailboxUsage;
    $usage->forceFill(['email' => 'owner@example.org', 'used_kib' => 1258291, 'limit_kib' => 5242880, 'checked_at' => now()])->save();

    // example.org's owners may forward (to this server only) and manage aliases; the column defaults
    // leave aliases off. example.net keeps the defaults.
    $selfService = new DomainSetting;
    $selfService->forceFill([
        'domain_id' => 10,
        'domain_name' => 'example.org',
        'allow_user_forwarding' => true,
        'allow_user_aliases' => true,
        'allow_external_forwarding' => false,
    ])->save();

    // DKIM keys the console knows (the DNS tab): example.org's is published in tests/e2e/fixtures/dns.json,
    // so its DNS checks are all green; example.net's is not, and the fixture has no DMARC for it either.
    foreach ([[10, 'example.org', E2E_DKIM_KEY], [11, 'example.net', 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAe2eExampleNetKeyNotPublishedIDAQAB']] as [$id, $name, $key]) {
        $dkim = new DomainDkim;
        $dkim->forceFill(['domain_id' => $id, 'domain_name' => $name, 'selector' => 'es20261001', 'txt' => "v=DKIM1; k=rsa; p={$key}", 'state' => 'active'])->save();
    }

    $admin = new User;
    $admin->forceFill([
        'name' => 'Admin',
        'email' => 'admin@example.org',
        'password' => 'Summersun-e2e-2026!',
        'password_set_at' => now(),
        'is_server_admin' => true,
        ...twoFactor(ADMIN_TOTP_SECRET),
    ])->save();

    // A pending invitation from the admin, with a note: the Access tab has a row, and /invite a valid link.
    $invitation = new Invitation;
    $invitation->forceFill([
        'token_hash' => hash('sha256', PENDING_INVITATION_TOKEN),
        'email' => 'pending@example.org',
        'domain_id' => 10,
        'domain_name' => 'example.org',
        'role' => 'viewer',
        'note' => 'Hi! This gives you a read-only look at example.org while we move the mailboxes over.',
        'locale' => 'en',
        'invited_by' => $admin->id,
        'expires_at' => now()->addHours(72),
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
