<?php

namespace App\MailServer;

use App\MailServer\Models\Domain;
use App\Models\DomainSetting;
use Illuminate\Support\Facades\DB;

/**
 * What a domain lets its mailbox owners change in the portal: their forwarding, their aliases, and
 * forwarding to addresses outside this server. Read from `domain_settings` while the row is the
 * domain's own (its `domain_name` is the guide row's name); a domain without one has the defaults,
 * which are the columns' defaults (spec decision 5).
 */
final class SelfService
{
    public const DEFAULTS = ['forwarding' => true, 'aliases' => false, 'external_forwarding' => false];

    /** The most forwarding targets a mailbox owner may set (spec decision 6). */
    public const MAX_TARGETS = 10;

    /** The most aliases a mailbox owner may have (spec decision 7). */
    public const MAX_ALIASES = 20;

    public function __construct(
        private readonly DomainLimits $limits,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{forwarding: bool, aliases: bool, external_forwarding: bool} */
    public static function for(Domain|int $domain): array
    {
        $domain = $domain instanceof Domain ? $domain : Domain::query()->find($domain);
        $setting = $domain === null ? null : DomainSetting::query()->find($domain->id);
        if ($domain === null || $setting === null || strtolower($setting->domain_name) !== strtolower($domain->name)) {
            return self::DEFAULTS;
        }

        return [
            'forwarding' => $setting->allow_user_forwarding,
            'aliases' => $setting->allow_user_aliases,
            'external_forwarding' => $setting->allow_external_forwarding,
        ];
    }

    /**
     * Sets the switches named in $values (a missing key keeps its value), writing the row with the
     * domain's current name, and audits `domain.self_service_changed` with the switches that really
     * changed, as `{field: [old, new]}`. A request that changes nothing writes neither row.
     *
     * @param  array{forwarding?: bool, aliases?: bool, external_forwarding?: bool}  $values
     */
    public function update(Domain $domain, array $values): void
    {
        DB::transaction(function () use ($domain, $values) {
            // The domain row first, as every writer does (lock order: domain, then its settings).
            $live = $this->limits->lockDomain($domain->id);
            $setting = DomainSetting::query()->whereKey($live->id)->lockForUpdate()->first();
            if ($setting === null) {
                $setting = new DomainSetting;
                $setting->forceFill(['domain_id' => $live->id]);
            }
            $owned = $setting->exists && strtolower($setting->domain_name) === strtolower($live->name);
            // A row left over from a renamed domain was never this domain's: its switches are the
            // defaults and its limits are dropped, so claiming it leaks nothing of the old domain.
            $current = $owned ? [
                'forwarding' => $setting->allow_user_forwarding,
                'aliases' => $setting->allow_user_aliases,
                'external_forwarding' => $setting->allow_external_forwarding,
            ] : self::DEFAULTS;
            if (! $owned && $setting->exists) {
                $setting->forceFill(['max_mailboxes' => null, 'max_quota_bytes_total' => null]);
            }

            $changes = [];
            foreach ($values as $field => $value) {
                if ($value !== $current[$field]) {
                    $changes[$field] = [$current[$field], $value];
                }
            }
            // No row for a request that changes nothing; a renamed leftover row is rewritten for this domain.
            if ($changes === [] && ($owned || ! $setting->exists)) {
                return;
            }

            $new = array_map(fn (array $pair) => $pair[1], $changes) + $current;
            $setting->forceFill([
                'domain_name' => $live->name,
                'allow_user_forwarding' => $new['forwarding'],
                'allow_user_aliases' => $new['aliases'],
                'allow_external_forwarding' => $new['external_forwarding'],
            ]);
            $setting->save();
            if ($changes !== []) {
                $this->audit->record('domain.self_service_changed', 'domain', $live->name, $live->name, $changes);
            }
        });
    }
}
