<?php

namespace App\Console\Commands;

use App\MailServer\GuideSchema;
use App\Support\GrantsPlaceholder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Prints the least-privilege SQL from backend plan section 4.1. The app
 * schema is granted per table so that audit_log can be INSERT, SELECT only:
 * MariaDB can't revoke a table privilege from a schema-wide grant.
 */
final class GrantsCommand extends Command
{
    protected $signature = 'emailsquire:grants
        {--app-user=emailsquire_app : DB user for the web app, queue and scheduler}
        {--host=localhost : Host part of the app user}
        {--database= : Connection that reads the app table list (default: the default connection); use migrator}';

    protected $description = 'Print the least-privilege GRANT statements for the EmailSquire database users';

    /**
     * Every table the app's migrations create, plus `migrations`. Grants prints a
     * statement per table, so it refuses to run against a schema missing one.
     */
    public const REQUIRED_TABLES = [
        'users', 'password_reset_tokens', 'sessions', 'cache', 'cache_locks',
        'jobs', 'job_batches', 'failed_jobs',
        'domain_grants', 'invitations', 'domain_settings', 'mailbox_settings',
        'disabled_mailboxes', 'blocked_addresses', 'helper_tasks', 'audit_log',
        'mailbox_usage', 'server_storage', 'pinned_domains',
        'migrations',
    ];

    private const USER_NAME_PATTERN = '/^[a-z_][a-z0-9_-]{0,31}$/';

    private const READ_WRITE = 'SELECT, INSERT, UPDATE, DELETE';

    public function handle(): int
    {
        $user = $this->option('app-user');
        $host = $this->option('host');

        if (! is_string($user) || preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $user) !== 1) {
            return $this->refuse('--app-user must be a plain user name (letters, digits, _ . -).');
        }

        if (! is_string($host) || preg_match('/^[A-Za-z0-9_.%:-]{1,255}$/', $host) !== 1) {
            return $this->refuse('--host must be a plain host name, IP address or pattern.');
        }

        $connection = $this->option('database') ?? DB::getDefaultConnection();

        if (preg_match('/^[A-Za-z0-9_-]+$/', $connection) !== 1 || ! is_array(config("database.connections.{$connection}"))) {
            return $this->refuse('--database must name a connection configured in config/database.php.');
        }

        $app = DB::connection()->getDatabaseName();
        $guide = GuideSchema::name();
        $appUser = "'{$user}'@'{$host}'";
        $vmailName = config('emailsquire.vmail_user');
        $rspamdName = config('emailsquire.rspamd_user');

        if (! is_string($vmailName) || ! is_string($rspamdName)
            || preg_match(self::USER_NAME_PATTERN, $vmailName) !== 1
            || preg_match(self::USER_NAME_PATTERN, $rspamdName) !== 1) {
            return $this->refuse('Invalid helper user name in config emailsquire.vmail_user / rspamd_user: use a Unix user name (lowercase letters, digits, _ -, at most 32 characters).');
        }

        $vmail = "'{$vmailName}'@'localhost'";
        $rspamd = "'{$rspamdName}'@'localhost'";

        // Read through --database: the app user can't see tables it has no grant on yet,
        // so its own view would silently drop grants (or all of them, before migrating).
        $tables = $this->visibleTables($connection, $app);

        $missing = array_values(array_diff(self::REQUIRED_TABLES, $tables));

        if ($missing !== []) {
            return $this->refuse("App tables not visible on connection \"{$connection}\": run php artisan migrate --database={$connection} first, then re-run with --database={$connection}. Missing: ".implode(', ', $missing).'.');
        }

        $lines = [
            '-- EmailSquire least-privilege grants. Order: 1. php artisan migrate --database=migrator',
            '-- 2. php artisan emailsquire:grants --database=migrator  3. run this output as MariaDB root.',
            '-- Replace '.GrantsPlaceholder::PASSWORD.' first.',
            '',
            '-- Web, queue and scheduler (put the password in .env)',
            self::existingAccountNote($appUser),
            "CREATE USER IF NOT EXISTS {$appUser} IDENTIFIED BY '".GrantsPlaceholder::PASSWORD."';",
        ];

        foreach (['virtual_domains', 'virtual_users', 'virtual_aliases'] as $table) {
            $lines[] = 'GRANT '.self::READ_WRITE.' ON '.self::qualify($guide, $table)." TO {$appUser};";
        }

        foreach (array_diff($tables, ['migrations']) as $table) {
            $privileges = $table === 'audit_log' ? 'INSERT, SELECT' : self::READ_WRITE;
            $lines[] = "GRANT {$privileges} ON ".self::qualify($app, $table)." TO {$appUser};";
        }

        // Read-only, so `emailsquire:doctor` (which uses these credentials) can see pending migrations.
        $lines[] = 'GRANT SELECT ON '.self::qualify($app, 'migrations')." TO {$appUser};";

        array_push(
            $lines,
            '',
            '-- Helpers: unix_socket auth, DB user name = OS user name, no password',
            self::existingAccountNote($vmail),
            "CREATE USER IF NOT EXISTS {$vmail} IDENTIFIED VIA unix_socket;",
            // The helper only checks that an address exists; it must not read password hashes.
            'GRANT SELECT (`email`) ON '.self::qualify($guide, 'virtual_users')." TO {$vmail};",
            'GRANT SELECT, UPDATE ON '.self::qualify($app, 'helper_tasks')." TO {$vmail};",
            self::existingAccountNote($rspamd),
            "CREATE USER IF NOT EXISTS {$rspamd} IDENTIFIED VIA unix_socket;",
            'GRANT SELECT ON '.self::qualify($guide, 'virtual_domains')." TO {$rspamd};",
            'GRANT SELECT, UPDATE ON '.self::qualify($app, 'helper_tasks')." TO {$rspamd};",
        );

        foreach ($lines as $line) {
            $this->output->writeln($line, OutputInterface::OUTPUT_RAW);
        }

        return self::SUCCESS;
    }

    /**
     * The app schema's base tables that the given connection can see.
     * `migrations` is in the list but only ever gets SELECT: only the
     * migrator (root) writes it.
     *
     * @return list<string>
     */
    private function visibleTables(string $connection, string $schema): array
    {
        $rows = DB::connection($connection)->select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE'
              ORDER BY TABLE_NAME",
            [$schema],
        );

        $tables = [];
        foreach ($rows as $row) {
            /** @var object{name: string} $row */
            $tables[] = (string) $row->name;
        }

        return $tables;
    }

    /** Writes the error to stderr (stdout stays empty) and returns the failure code. */
    private function refuse(string $message): int
    {
        $this->output->getErrorStyle()->writeln('<error>'.OutputFormatter::escape($message).'</error>');

        return self::FAILURE;
    }

    /** CREATE USER IF NOT EXISTS keeps an existing account's extra privileges; say so. */
    private static function existingAccountNote(string $account): string
    {
        return "-- If this account already exists, check SHOW GRANTS FOR {$account}; and revoke anything beyond the grants below.";
    }

    private static function qualify(string $schema, string $table): string
    {
        return '`'.str_replace('`', '``', $schema).'`.`'.str_replace('`', '``', $table).'`';
    }
}
