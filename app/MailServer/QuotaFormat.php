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
     * A limit as the console's formatBytes() lays sizes out, in the current locale: binary gigabytes with
     * at most one decimal ("10.5 GB", "10,5 Gt"), or whole megabytes below 1 GiB ("512 MB", "512 Mt").
     * Unlike formatBytes(), which rounds to nearest, it rounds UP to the shown precision, so a limit is
     * never understated: 10 GiB and a byte reads "10.1 GB", not "10 GB". Integer arithmetic, so no
     * float error decides the last digit. The units come from `emailsquire.units`.
     */
    public static function size(int $bytes): string
    {
        if ($bytes < self::GIB) {
            return trans()->string('emailsquire.units.mb', ['n' => (string) intdiv($bytes + self::MIB - 1, self::MIB)]);
        }

        $tenths = intdiv($bytes * 10 + self::GIB - 1, self::GIB);
        $text = intdiv($tenths, 10).($tenths % 10 === 0 ? '' : trans()->string('emailsquire.units.decimal_separator').($tenths % 10));

        return trans()->string('emailsquire.units.gb', ['n' => $text]);
    }
}
