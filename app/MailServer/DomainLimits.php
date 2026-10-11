<?php

namespace App\MailServer;

use App\MailServer\Exceptions\DomainLimitExceeded;
use App\MailServer\Models\Domain;
use App\MailServer\Queries\DomainList;
use App\Models\DomainSetting;
use Illuminate\Support\Facades\DB;

/**
 * A domain's mailbox count and total storage limits (`domain_settings`; `null` is unlimited) and
 * what its mailboxes take against them. They bind server admins too.
 *
 * assertRoom() must run inside the repository transaction after the domain row was taken
 * `FOR UPDATE` (lockDomain()), so two writers at the limit cannot both count the same room.
 */
class DomainLimits
{
    public function __construct(
        private readonly DomainList $list,
        private readonly AuditLog $audit,
    ) {}

    /** @return array{max_mailboxes: ?int, max_quota_bytes_total: ?int} */
    public function limits(Domain $d): array
    {
        $setting = $this->setting($d);

        return [
            'max_mailboxes' => $setting?->max_mailboxes,
            'max_quota_bytes_total' => $setting?->max_quota_bytes_total,
        ];
    }

    /** @return array{mailboxes: int, quota_bytes_allocated: int} */
    public function usage(Domain $d): array
    {
        return $this->list->usage($d->id);
    }

    /**
     * Throws when adding $addMailboxes mailboxes and $addQuotaBytes of quota would pass a limit. Only
     * growth is checked: a write that adds nothing (or frees room) passes even over a lowered limit.
     */
    public function assertRoom(Domain $d, int $addMailboxes, int $addQuotaBytes): void
    {
        $limits = $this->limits($d);
        $mailboxLimit = $addMailboxes > 0 ? $limits['max_mailboxes'] : null;
        $quotaLimit = $addQuotaBytes > 0 ? $limits['max_quota_bytes_total'] : null;
        if ($mailboxLimit === null && $quotaLimit === null) {
            return;
        }

        $usage = $this->usage($d);
        if ($mailboxLimit !== null && $mailboxLimit < $usage['mailboxes'] + $addMailboxes) {
            throw new DomainLimitExceeded('mailboxes', $mailboxLimit);
        }
        if ($quotaLimit !== null && $quotaLimit < $usage['quota_bytes_allocated'] + $addQuotaBytes) {
            throw new DomainLimitExceeded('quota', $quotaLimit);
        }
    }

    /**
     * The domain row, re-read and locked `FOR UPDATE`: the first lock of every write that checks the
     * limits (lock order: domain, mailboxes, aliases). A domain deleted meanwhile is a 404.
     */
    public function lockDomain(int $domainId): Domain
    {
        return Domain::query()->whereKey($domainId)->lockForUpdate()->firstOrFail();
    }

    /**
     * Sets the limits named in $values (a missing key keeps its value), writing the row with the
     * domain's current name, and audits `domain.limits_changed` with the fields that really changed.
     * A limit below current use is accepted: existing mailboxes stay, new ones are refused.
     *
     * @param  array{max_mailboxes?: ?int, max_quota_bytes_total?: ?int}  $values
     */
    public function update(Domain $d, array $values): void
    {
        DB::transaction(function () use ($d, $values) {
            // The domain row first, as every writer does; a concurrent delete then either went first
            // (404) or waits and removes this row with the domain.
            $live = $this->lockDomain($d->id);

            $setting = DomainSetting::query()->whereKey($live->id)->lockForUpdate()->first();
            if ($setting === null) {
                $setting = new DomainSetting;
                $setting->forceFill(['domain_id' => $live->id]);
            } elseif (! $this->owned($setting, $live)) {
                // Left over from a domain renamed outside EmailSquire: its limits and switches were never this domain's.
                $setting->forceFill([
                    'max_mailboxes' => null,
                    'max_quota_bytes_total' => null,
                    'allow_user_forwarding' => SelfService::DEFAULTS['forwarding'],
                    'allow_user_aliases' => SelfService::DEFAULTS['aliases'],
                    'allow_external_forwarding' => SelfService::DEFAULTS['external_forwarding'],
                ]);
            }
            $current = ['max_mailboxes' => $setting->max_mailboxes, 'max_quota_bytes_total' => $setting->max_quota_bytes_total];

            $changes = [];
            foreach (['max_mailboxes', 'max_quota_bytes_total'] as $field) {
                if (array_key_exists($field, $values) && $values[$field] !== $current[$field]) {
                    $changes[$field] = [$current[$field], $values[$field]];
                }
            }

            $setting->forceFill(['domain_name' => $live->name] + array_intersect_key($values, $changes));
            // No row for a request that changes nothing; a renamed leftover row is rewritten for this domain.
            if ($changes !== [] || ($setting->exists && $setting->isDirty())) {
                $setting->save();
            }

            if ($changes !== []) {
                $this->audit->record('domain.limits_changed', 'domain', $live->name, $live->name, $changes);
            }
        });
    }

    private function setting(Domain $d): ?DomainSetting
    {
        $setting = DomainSetting::query()->find($d->id);

        return $setting !== null && $this->owned($setting, $d) ? $setting : null;
    }

    private function owned(DomainSetting $setting, Domain $d): bool
    {
        return strtolower($setting->domain_name) === strtolower($d->name);
    }
}
