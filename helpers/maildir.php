<?php

/**
 * The maildir helper's command line, run as vmail by emailsquire-maildir.service (docs/backend-plan.md
 * §3 and §9). Framework-free: it loads only its own classes and never reads .env. It connects over the
 * MariaDB unix socket as the effective OS user, with no password.
 *
 * Exit codes: 0 when everything succeeded, 1 when a task failed or a purge entry was refused (or the
 * run itself broke off), 2 for a usage or connection error.
 */

declare(strict_types=1);

require_once __DIR__.'/maildir/UnsafePath.php';
require_once __DIR__.'/maildir/SafePath.php';
require_once __DIR__.'/maildir/MaildirHelper.php';

use EmailSquire\Helpers\MaildirHelper;

$fail = static function (string $problem, bool $usage = true): never {
    fwrite(STDERR, addcslashes($problem, "\0..\37\177").PHP_EOL.($usage ? 'usage: php maildir.php run|purge [--mail-root=/var/vmail]'
        .' [--trash-dir=<mail-root>/.emailsquire-trash] [--guide-schema=mailserver] [--app-schema=emailsquire]'
        .' [--socket=/run/mysqld/mysqld.sock] [--retention-days=30]'.PHP_EOL : ''));
    exit(2);
};

// getopt() stops at the first non-option (the mode) and skips unknown options, so parse by hand.
$options = ['mail-root' => '/var/vmail', 'trash-dir' => null, 'guide-schema' => 'mailserver',
    'app-schema' => 'emailsquire', 'socket' => '/run/mysqld/mysqld.sock', 'retention-days' => '30'];
$args = array_slice($argv, 1);
$mode = array_shift($args);
if ($mode !== 'run' && $mode !== 'purge') {
    $fail('the first argument must be run or purge');
}
$given = [];
foreach ($args as $arg) {
    if (preg_match('/^--([a-z-]+)=(.*)$/sD', $arg, $match) !== 1 || ! array_key_exists($match[1], $options) || isset($given[$match[1]])) {
        $fail("unknown, repeated or malformed option: {$arg}");
    }
    $given[$match[1]] = $match[2];
}
$mailRoot = $given['mail-root'] ?? $options['mail-root'];
$trashDir = $given['trash-dir'] ?? rtrim($mailRoot, '/').'/.emailsquire-trash';
$guideSchema = $given['guide-schema'] ?? $options['guide-schema'];
$appSchema = $given['app-schema'] ?? $options['app-schema'];
$socket = $given['socket'] ?? $options['socket'];
$retention = $given['retention-days'] ?? $options['retention-days'];

if (preg_match('/^[1-9][0-9]{0,4}$/D', $retention) !== 1) {
    $fail('--retention-days must be a whole number of days from 1 to 99999');
}
if (! str_starts_with($socket, '/') || str_contains($socket, ';')) {
    $fail('--socket must be an absolute path without ;');
}
try {
    MaildirHelper::checkSettings($guideSchema, $appSchema, $mailRoot, $trashDir);
} catch (InvalidArgumentException $e) {
    $fail($e->getMessage());
}

// run and purge may overlap; a restore must not race a purge of a just-expired archive. The handle
// stays open until the process exits, which releases the lock.
$lock = @fopen(rtrim($mailRoot, '/').'/.emailsquire-maildir.lock', 'c');
if ($lock === false) {
    $fail('cannot open the lock file in the mail root', false);
}
chmod(rtrim($mailRoot, '/').'/.emailsquire-maildir.lock', 0600);
if (! flock($lock, LOCK_EX | LOCK_NB)) {
    echo 'another maildir helper run is active; skipping'.PHP_EOL;
    exit(0);
}

// Purge reads no tasks, but connects too: a misconfigured unit file then fails on its first run.
try {
    $user = posix_getpwuid(posix_geteuid()) ?: throw new PDOException('no user name for uid '.posix_geteuid());
    $db = new PDO("mysql:unix_socket={$socket};charset=utf8mb4", $user['name'], null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $helper = new MaildirHelper($db, $guideSchema, $appSchema, $mailRoot, $trashDir);
} catch (PDOException $e) {
    $fail('cannot connect: '.$e->getMessage(), false);
}

try {
    $counts = $mode === 'run' ? $helper->run() : $helper->purge((int) $retention);
} catch (Throwable $e) {
    fwrite(STDERR, addcslashes("{$mode} stopped: ".$e::class.': '.$e->getMessage(), "\0..\37\177").PHP_EOL);
    exit(1);
}
echo $mode.': '.http_build_query($counts, '', ', ').PHP_EOL;
exit(($counts['failed'] ?? 0) + ($counts['refused'] ?? 0) > 0 ? 1 : 0);
