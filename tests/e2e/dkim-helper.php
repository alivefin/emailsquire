<?php

/*
 * Plays the DKIM helper's part in the Playwright run, where no emailsquire-dkim.timer runs: the real
 * EmailSquire\Helpers\Dkim\DkimHelper works through the pending `dkim.*` tasks of the e2e schemas, with a
 * throwaway key directory and selector map that are removed again afterwards. dns.spec runs it after
 * Generate, so the console reads back a real helper result (a fresh RSA key and its record). The real
 * helper's privilege, path and unit rules are covered by its own tests and by mail truth (DkimTest).
 * It refuses to run unless .env.e2e is the loaded configuration, like seed.php.
 */

declare(strict_types=1);

use EmailSquire\Helpers\Dkim\DkimHelper;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

function fail(string $message): never
{
    fwrite(STDERR, "dkim-helper: {$message}\n");
    exit(1);
}

putenv('APP_ENV=e2e');
$_ENV['APP_ENV'] = 'e2e';
$_SERVER['APP_ENV'] = 'e2e';

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = config('database.connections.'.config('database.default').'.database');
if (app()->environment() !== 'e2e' || $database !== 'emailsquire_e2e' || config('emailsquire.guide_schema') !== 'mailserver_e2e') {
    fail('refusing to run: .env.e2e is not the loaded configuration (is the config cached?).');
}

// The helper refuses a symlink anywhere on the path, and macOS's /var is one: start from the real path.
$root = realpath(sys_get_temp_dir()).'/emailsquire-e2e-dkim-'.bin2hex(random_bytes(6));
$keys = "{$root}/dkim";
$mapDir = "{$root}/rspamd-emailsquire";
umask(0077);
if (! mkdir($keys, 0700, true) || ! mkdir($mapDir, 0700)) {
    fail('cannot create the throwaway key directory');
}

try {
    $counts = (new DkimHelper(DB::connection()->getPdo(), 'mailserver_e2e', 'emailsquire_e2e', $keys, "{$mapDir}/dkim_selectors.map", log: static fn () => null))->run();
} finally {
    foreach ([...(glob("{$keys}/{,.}*", GLOB_BRACE) ?: []), ...(glob("{$mapDir}/{,.}*", GLOB_BRACE) ?: [])] as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    @rmdir($keys);
    @rmdir($mapDir);
    @rmdir($root);
}

echo 'dkim-helper: '.http_build_query($counts, '', ', ').PHP_EOL;
exit($counts['failed'] > 0 ? 1 : 0);
