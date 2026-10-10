<?php

namespace App\MailServer;

final class QuotaFormat
{
    /** A stored 0 means the Dovecot default; the API only ever reports the effective value. */
    public static function effectiveBytes(int $stored): int
    {
        return $stored === 0 ? config()->integer('emailsquire.quota_default_bytes') : $stored;
    }
}
