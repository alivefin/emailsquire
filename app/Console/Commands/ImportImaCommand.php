<?php

namespace App\Console\Commands;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\GuideSchema;
use App\MailServer\Repositories\BlockedAddressRepository;
use App\MailServer\Repositories\ImaBlacklistRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Copies ISPmail Admin's `blacklist_email` into `blocked_addresses`. IMA stored
 * input after htmlentities(), so addresses and reasons are decoded first.
 */
final class ImportImaCommand extends Command
{
    protected $signature = 'emailsquire:import-ima
        {--drop : Drop blacklist_email afterwards, unless a row was refused}
        {--database= : Connection that reads and drops blacklist_email (default: the default connection); use migrator}';

    protected $description = "Import ISPmail Admin's blacklist_email table into blocked addresses";

    public function handle(AddressNormalizer $normalizer, BlockedAddressRepository $blocked): int
    {
        $connection = $this->option('database') ?? DB::getDefaultConnection();

        if (preg_match('/^[A-Za-z0-9_-]+$/', $connection) !== 1 || ! is_array(config("database.connections.{$connection}"))) {
            $this->components->error('--database must name a connection configured in config/database.php.');

            return self::FAILURE;
        }

        $ima = new ImaBlacklistRepository($connection);
        $table = GuideSchema::name().'.blacklist_email';

        if (! $ima->exists()) {
            $this->line("No blacklist_email table is visible on the \"{$connection}\" connection. If ISPmail Admin's table exists, try --database=migrator.");

            return self::SUCCESS;
        }

        $imported = 0;
        $skipped = 0;
        $refused = [];

        foreach ($ima->rows() as $row) {
            $address = self::decode($row['address']);
            $reason = self::decode($row['reason']);
            $reason = $reason === '' ? null : mb_substr($reason, 0, 255);

            try {
                $blocked->block($normalizer->address($address), $reason);
                $imported++;
            } catch (InvalidAddress) {
                $refused[] = $address;
            } catch (Conflict) {
                $skipped++;
            }
        }

        $this->line("Imported {$imported}, skipped {$skipped} already blocked, refused ".count($refused).($refused === [] ? '' : ': '.implode(', ', $refused)));

        if (! $this->option('drop')) {
            return self::SUCCESS;
        }

        if ($refused !== []) {
            $this->components->error("{$table} not dropped: fix or delete the refused rows listed above, then run again.");

            return self::FAILURE;
        }

        $ima->drop();
        $this->line("Dropped {$table}.");

        return self::SUCCESS;
    }

    private static function decode(string $value): string
    {
        return trim(html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
