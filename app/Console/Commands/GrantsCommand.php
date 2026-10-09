<?php

namespace App\Console\Commands;

use App\MailServer\GuideSchema;
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
        $vmail = "'".$this->configString('emailsquire.vmail_user', 'vmail')."'@'localhost'";
        $rspamd = "'".$this->configString('emailsquire.rspamd_user', '_rspamd')."'@'localhost'";

        // Read through --database: the app user can't see tables it has no grant on yet,
        // so its own view would silently drop grants (or all of them, before migrating).
        $tables = $this->visibleTables($connection, $app);

        if (! in_array('audit_log', $tables, true) || ! in_array('migrations', $tables, true)) {
            return $this->refuse("App tables not visible on connection \"{$connection}\": run php artisan migrate --database={$connection} first, then re-run with --database={$connection}.");
        }

        $lines = [
            '-- EmailSquire least-privilege grants. Order: 1. php artisan migrate --database=migrator',
            '-- 2. php artisan emailsquire:grants --database=migrator  3. run this output as MariaDB root.',
            '-- Replace CHANGE-ME first.',
            '',
            '-- Web, queue and scheduler (put the password in .env)',
            self::existingAccountNote($appUser),
            "CREATE USER IF NOT EXISTS {$appUser} IDENTIFIED BY 'CHANGE-ME';",
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
            'GRANT SELECT ON '.self::qualify($guide, 'virtual_users')." TO {$vmail};",
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

    private function configString(string $key, string $default): string
    {
        $value = config($key, $default);

        return is_string($value) && preg_match('/^[A-Za-z0-9_.-]+$/', $value) === 1 ? $value : $default;
    }
}
