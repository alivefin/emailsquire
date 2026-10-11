<?php

namespace App\Doctor\Support;

/**
 * Whether Postfix's `virtual_alias_maps` include the ISPmail guide's email2email map, which a catch-all
 * (`@domain`) needs: Postfix looks an existing mailbox up in every alias map, email2email answers it with
 * itself, and so the catch-all never takes that mailbox's mail. Without it, a catch-all would.
 *
 * Shared by doctor's `postfix.alias_maps` check and App\MailServer\CatchAllSupport (the console's gate).
 * A map file that can be read counts when its query is the guide's email2email query, alone or with
 * backend plan §10's `enabled`/`sendonly` gates (isEmail2Email()), whatever the file is called. One that
 * can't be read (the installer makes it readable by root and postfix only) counts when its file name
 * contains `email2email`, the installer's name.
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
            if (is_file($file) && is_readable($file)) {
                if (self::isEmail2Email(PostfixMapFile::query($file))) {
                    return self::SUPPORTED;
                }

                continue; // read, and something else, whatever its name says
            }
            if (str_contains(basename($file), 'email2email')) {
                return self::SUPPORTED;
            }
            $unreadable = true;
        }

        return $unreadable ? self::UNREADABLE : self::MISSING;
    }

    /**
     * The guide's identity query, alone or with backend plan §10's gates (`AND enabled = 1`,
     * `AND sendonly = 0`), which keep it answering every mailbox that receives mail. Keywords and the
     * spaces around `=` may differ; any other condition, or a gate the other way round, is not it.
     */
    public static function isEmail2Email(?string $query): bool
    {
        if ($query === null) {
            return false;
        }

        return preg_match(
            "/^select\s+email\s+from\s+virtual_users\s+where\s+email\s*=\s*'%s'(?:\s+and\s+(?:enabled\s*=\s*1|sendonly\s*=\s*0))*\s*;?$/iD",
            trim($query),
        ) === 1;
    }
}
