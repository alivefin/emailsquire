<?php

/**
 * The DKIM helper's command line, run as _rspamd by emailsquire-dkim.service (docs/backend-plan.md §3
 * and §8). Framework-free: it loads only its own classes and never reads .env. It connects over the
 * MariaDB unix socket as the effective OS user, with no password.
 *
 * Exit codes: 0 when every task succeeded (or will be retried), 1 when a task failed (or the run itself
 * broke off), 2 for a usage or connection error.
 */

declare(strict_types=1);

require_once __DIR__.'/dkim/Refused.php';
require_once __DIR__.'/dkim/Io.php';
require_once __DIR__.'/dkim/PathGuard.php';
require_once __DIR__.'/dkim/KeyStore.php';
require_once __DIR__.'/dkim/SelectorMap.php';
require_once __DIR__.'/dkim/DkimHelper.php';

use EmailSquire\Helpers\Dkim\DkimHelper;

$fail = static function (string $problem, bool $usage = true): never {
    fwrite(STDERR, addcslashes($problem, "\0..\37\177").PHP_EOL.($usage ? 'usage: php dkim.php run [--dkim-dir=/var/lib/rspamd/dkim]'
        .' [--selector-map=/etc/rspamd/dkim_selectors.map] [--guide-schema=mailserver] [--app-schema=emailsquire]'
        .' [--socket=/run/mysqld/mysqld.sock]'.PHP_EOL : ''));
    exit(2);
};

// getopt() stops at the first non-option (the mode) and skips unknown options, so parse by hand.
$options = ['dkim-dir' => '/var/lib/rspamd/dkim', 'selector-map' => '/etc/rspamd/dkim_selectors.map',
    'guide-schema' => 'mailserver', 'app-schema' => 'emailsquire', 'socket' => '/run/mysqld/mysqld.sock'];
$args = array_slice($argv, 1);
if (array_shift($args) !== 'run') {
    $fail('the first argument must be run');
}
$given = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/sD', $arg, $match) !== 1 || ! array_key_exists($match[1], $options) || isset($given[$match[1]])) {
        $fail("unknown, repeated or malformed option: {$arg}");
    }
    $given[$match[1]] = $match[2];
}
[$dkimDir, $selectorMap, $guideSchema, $appSchema, $socket] = array_values(array_merge($options, $given));

if (! str_starts_with($socket, '/') || str_contains($socket, ';')) {
    $fail('--socket must be an absolute path without ;');
}
try {
    DkimHelper::checkSettings($guideSchema, $appSchema, $dkimDir, $selectorMap);
} catch (InvalidArgumentException $e) {
    $fail($e->getMessage());
}

// No root (backend plan §3): only the key directory's owner may run, or root-owned keys and lock files
// would break every later _rspamd run. MariaDB's unix_socket auth would let root connect, so check here.
if (($owner = @fileowner($dkimDir)) === false || posix_geteuid() !== $owner) {
    $name = $owner === false ? '' : (posix_getpwuid($owner) ?: ['name' => (string) $owner])['name'];
    $fail($owner === false ? "cannot read the owner of the DKIM directory {$dkimDir}" : "run the helper as {$name}, the owner of"
        ." {$dkimDir}: runuser -u {$name} -- php ".implode(' ', array_map(escapeshellarg(...), $argv)), false);
}

// One run at a time. The handle stays open until the process exits, which releases the lock. umask,
// not chmod: chmod would follow a symlink.
umask(0077);
$lockPath = rtrim($dkimDir, '/').'/.emailsquire-dkim.lock';
$lock = is_link($lockPath) ? $fail("the lock file {$lockPath} is a symlink; remove it", false) : @fopen($lockPath, 'c');
if ($lock === false) {
    $fail('cannot open the lock file in the DKIM directory', false);
}
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'another dkim helper run is active; skipping'.PHP_EOL;
    exit(0);
}

try {
    $user = posix_getpwuid(posix_geteuid()) ?: throw new PDOException('no user name for uid '.posix_geteuid());
    $db = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", $user['name'], null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $helper = new DkimHelper($db, $guideSchema, $appSchema, $dkimDir, $selectorMap);
} catch (PDOException $e) {
    $fail('cannot connect: '.$e->getMessage(), false);
}

try {
    $counts = $helper->run();
} catch (Throwable $e) {
    fwrite(STDERR, addcslashes('run stopped: '.$e::class.': '.$e->getMessage(), "\0..\37\177").PHP_EOL);
    exit(1);
}
echo 'run: '.http_build_query($counts, '', ', ').PHP_EOL;
exit($counts['failed'] > 0 ? 1 : 0);
