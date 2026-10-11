<?php

namespace App\MailServer;

use App\MailServer\Models\Domain;
use App\Models\DomainSetting;

/**
 * What a domain lets its mailbox owners change in the portal: their forwarding, their aliases, and
 * forwarding to addresses outside this server. Read from `domain_settings` while the row is the
 * domain's own (its `domain_name` is the guide row's name); a domain without one has the defaults,
 * which are the columns' defaults (spec decision 5).
 */
final class SelfService
{
    public const DEFAULTS = ['forwarding' => true, 'aliases' => false, 'external_forwarding' => false];

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
}
