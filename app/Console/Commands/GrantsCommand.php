<?php

namespace App\Console\Commands;

use App\MailServer\GuideSchema;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
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
        {--host=localhost : Host part of the app user}';

    protected $description = 'Print the least-privilege GRANT statements for the EmailSquire database users';

    private const READ_WRITE = 'SELECT, INSERT, UPDATE, DELETE';

    public function handle(): int
    {
        $user = $this->option('app-user');
        $host = $this->option('host');

        if (! is_string($user) || preg_match('/^[A-Za-z0-9_.-]{1,80}$/', $user) !== 1) {
            $this->error('--app-user must be a plain user name (letters, digits, _ . -).');

            return self::FAILURE;
        }

        if (! is_string($host) || preg_match('/^[A-Za-z0-9_.%:-]{1,255}$/', $host) !== 1) {
            $this->error('--host must be a plain host name, IP address or pattern.');

            return self::FAILURE;
        }

        $app = DB::connection()->getDatabaseName();
        $guide = GuideSchema::name();
        $appUser = "'{$user}'@'{$host}'";
        $vmail = "'".$this->configString('emailsquire.vmail_user', 'vmail')."'@'localhost'";
        $rspamd = "'".$this->configString('emailsquire.rspamd_user', '_rspamd')."'@'localhost'";

        $lines = [
            '-- EmailSquire least-privilege grants. Run as MariaDB root; replace CHANGE-ME first.',
            '-- Schema migrations run as root over unix_socket: php artisan migrate --database=migrator',
            '',
            '-- Web, queue and scheduler (put the password in .env)',
            "CREATE USER IF NOT EXISTS {$appUser} IDENTIFIED BY 'CHANGE-ME';",
        ];

        foreach (['virtual_domains', 'virtual_users', 'virtual_aliases'] as $table) {
            $lines[] = 'GRANT '.self::READ_WRITE.' ON '.self::qualify($guide, $table)." TO {$appUser};";
        }

        foreach ($this->appTables($app) as $table) {
            $privileges = $table === 'audit_log' ? 'INSERT, SELECT' : self::READ_WRITE;
            $lines[] = "GRANT {$privileges} ON ".self::qualify($app, $table)." TO {$appUser};";
        }

        // Read-only, so `emailsquire:doctor` (which uses these credentials) can see pending migrations.
        $lines[] = 'GRANT SELECT ON '.self::qualify($app, 'migrations')." TO {$appUser};";

        array_push(
            $lines,
            '',
            '-- Helpers: unix_socket auth, DB user name = OS user name, no password',
            "CREATE USER IF NOT EXISTS {$vmail} IDENTIFIED VIA unix_socket;",
            'GRANT SELECT ON '.self::qualify($guide, 'virtual_users')." TO {$vmail};",
            'GRANT SELECT, UPDATE ON '.self::qualify($app, 'helper_tasks')." TO {$vmail};",
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
     * Every base table in the app schema except `migrations`, which only
     * the migrator (root) writes; the app user gets SELECT on it separately.
     *
     * @return list<string>
     */
    private function appTables(string $schema): array
    {
        $rows = DB::select(
            "SELECT TABLE_NAME AS name FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'migrations'
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
