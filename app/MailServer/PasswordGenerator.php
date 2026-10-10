<?php

namespace App\MailServer;

final class PasswordGenerator
{
    public const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789-_!';

    public const LENGTH = 16;

    /** Draws with random_int and redraws until the result scores at least 3, so it always passes the mailbox rule. */
    public static function generate(): string
    {
        $max = strlen(self::ALPHABET) - 1;

        do {
            $pw = '';
            for ($i = 0; $i < self::LENGTH; $i++) {
                $pw .= self::ALPHABET[random_int(0, $max)];
            }
        } while (PasswordStrength::score($pw) < 3);

        return $pw;
    }
}
