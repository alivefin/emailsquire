<?php

namespace App\MailServer\Dovecot;

/** Storage used and its limit, in KiB, as read from a doveadm quotaGet reply. */
final readonly class QuotaUsage
{
    public function __construct(public int $usedKib, public ?int $limitKib) {}

    /**
     * Reads the STORAGE entry. Returns null when it is missing or its value is not a number;
     * a limit of "-" means no limit.
     *
     * @param  array<mixed>  $entries
     */
    public static function fromReply(array $entries): ?self
    {
        foreach ($entries as $entry) {
            if (! is_array($entry) || ($entry['type'] ?? null) !== 'STORAGE') {
                continue;
            }
            if (! is_numeric($entry['value'] ?? null)) {
                return null;
            }
            $limit = $entry['limit'] ?? null;

            return new self((int) $entry['value'], is_numeric($limit) ? (int) $limit : null);
        }

        return null;
    }
}
