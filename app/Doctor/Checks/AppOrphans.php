<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\MailServer\GuideSchema;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * App rows keyed by a guide domain_id whose domain was deleted or renamed
 * outside EmailSquire (for example in ISPmail Admin or by hand).
 */
final class AppOrphans extends BaseCheck
{
    /** table => [row label column, SQL for the domain name the row expects] */
    private const TABLES = [
        'domain_grants' => ['id', 'r.domain_name'],
        'domain_settings' => ['domain_id', 'r.domain_name'],
        'mailbox_settings' => ['mailbox_email', "SUBSTRING_INDEX(r.mailbox_email, '@', -1)"],
        'invitations' => ['id', 'r.domain_name'],
        'disabled_mailboxes' => ['mailbox_email', "SUBSTRING_INDEX(r.mailbox_email, '@', -1)"],
        'pinned_domains' => ['id', 'r.domain_name'],
        'domain_dns_checks' => ['domain_id', 'r.domain_name'],
    ];

    public function __construct(
        private readonly AppMigrated $migrated,
        private readonly GuideSchemaShape $shape,
    ) {}

    public function id(): string
    {
        return 'app.orphans';
    }

    public function title(): string
    {
        return 'App rows for missing domains';
    }

    public function run(): CheckResult
    {
        try {
            $ready = $this->migrated->pending() === [] && $this->shape->problems() === [];
        } catch (Throwable) {
            $ready = false;
        }

        if (! $ready) {
            return $this->result(Status::Skip, 'Skipped: the app schema is not migrated or the guide schema check failed.');
        }

        $items = [];
        foreach (self::TABLES as $table => [$label, $expectedName]) {
            $rows = DB::table($table.' as r')
                ->leftJoin(GuideSchema::table('virtual_domains').' as d', 'd.id', '=', 'r.domain_id')
                ->where(fn (Builder $q) => $q->whereNull('d.id')->orWhereRaw(
                    // The app and guide schemas may use different collations; compare bytes.
                    "CAST(LOWER({$expectedName}) AS BINARY) <> CAST(LOWER(d.name) AS BINARY)",
                ))
                ->whereNotNull('r.domain_id')
                ->orderBy("r.{$label}")
                ->get(["r.{$label} as label", 'r.domain_id', 'd.name', DB::raw("{$expectedName} AS expected")]);

            foreach ($rows as $row) {
                [$rowLabel, $domainId, $name, $expected] = self::strings([
                    $row->label ?? '', $row->domain_id ?? '', $row->name ?? '', $row->expected ?? '',
                ]);

                $items[] = $row->name === null
                    ? "{$table} {$rowLabel}: domain_id {$domainId} ({$expected}) no longer exists"
                    : "{$table} {$rowLabel}: domain_id {$domainId} is now {$name}, not {$expected}";
            }
        }

        $usage = DB::table('mailbox_usage as r')
            ->leftJoin(GuideSchema::table('virtual_users').' as u', fn (JoinClause $join) => $join->whereRaw(
                'CAST(LOWER(u.email) AS BINARY) = CAST(LOWER(r.email) AS BINARY)',
            ))
            ->whereNull('u.id')
            ->orderBy('r.email')
            ->pluck('r.email');

        foreach ($usage as $email) {
            $email = self::strings([$email])[0];
            $items[] = "mailbox_usage {$email}: the mailbox no longer exists";
        }

        if ($items === []) {
            return $this->result(Status::Ok, 'Every app row points at an existing guide domain or mailbox.');
        }

        return $this->result(Status::Warn, count($items).' app row(s) point at a missing domain or mailbox.', $items);
    }
}
