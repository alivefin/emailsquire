<?php

namespace App\Doctor\Support;

/**
 * Whether Postfix's `virtual_alias_maps` include the ISPmail guide's email2email map, which a catch-all
 * (`@domain`) needs: Postfix looks an existing mailbox up in every alias map, email2email answers it with
 * itself, and so the catch-all never takes that mailbox's mail. Without it, a catch-all would.
 *
 * Shared by doctor's `postfix.alias_maps` check and App\MailServer\CatchAllSupport (the console's gate).
 * A map counts when its file name contains `email2email` (the installer's name; the file itself may be
 * readable by root and postfix only) or its query is the guide's email2email query.
 */
final class AliasMaps
{
    public const EMAIL2EMAIL_QUERY = "SELECT email FROM virtual_users WHERE email='%s'";

    /** The email2email map is listed. */
    public const SUPPORTED = 'supported';

    /** main.cf was read, and no listed map is the email2email map. */
    public const MISSING = 'missing';

    /** There is no main.cf at the configured path. */
    public const NOT_FOUND = 'not_found';

    /** main.cf, or a listed map that might be the email2email map under another name, can't be read. */
    public const UNREADABLE = 'unreadable';

    /**
     * @return self::SUPPORTED|self::MISSING|self::NOT_FOUND|self::UNREADABLE
     */
    public static function inspect(mixed $mainCf): string
    {
        if (! is_string($mainCf) || ! is_file($mainCf)) {
            return self::NOT_FOUND;
        }
        if (! is_readable($mainCf)) {
            return self::UNREADABLE;
        }

        $unreadable = false;
        foreach (PostfixConfig::fromFile($mainCf)->mapFiles('virtual_alias_maps') as $file) {
            if (str_contains(basename($file), 'email2email')) {
                return self::SUPPORTED;
            }
            if (! is_readable($file)) {
                $unreadable = true;

                continue;
            }
            if (PostfixMapFile::query($file) === self::EMAIL2EMAIL_QUERY) {
                return self::SUPPORTED;
            }
        }

        return $unreadable ? self::UNREADABLE : self::MISSING;
    }
}
