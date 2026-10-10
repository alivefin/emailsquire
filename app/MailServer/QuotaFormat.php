<?php

namespace App\MailServer;

final class QuotaFormat
{
    private const GIB = 1073741824;

    private const MIB = 1048576;

    /** A stored 0 means the Dovecot default; the API only ever reports the effective value. */
    public static function effectiveBytes(int $stored): int
    {
        return $stored === 0 ? config()->integer('emailsquire.quota_default_bytes') : $stored;
    }

    /**
     * A size as the console's formatBytes() shows it, in the current locale: binary gigabytes with one
     * decimal and a trailing ".0" dropped ("10.5 GB", "10,5 Gt"), or whole megabytes below 1 GiB
     * ("512 MB", "512 Mt"). The units come from `emailsquire.units`.
     */
    public static function size(int $bytes): string
    {
        if ($bytes < self::GIB) {
            return trans()->string('emailsquire.units.mb', ['n' => (string) (int) round($bytes / self::MIB)]);
        }

        $text = rtrim(rtrim(number_format($bytes / self::GIB, 1, '.', ''), '0'), '.');
        $decimal = trans()->string('emailsquire.units.decimal_separator');

        return trans()->string('emailsquire.units.gb', ['n' => str_replace('.', $decimal, $text)]);
    }
}
