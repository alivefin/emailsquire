<?php

namespace App\MailServer;

/** Scores a password 0-4. The SPA mirrors this algorithm exactly; change both together. */
final class PasswordStrength
{
    public static function score(string $pw): int
    {
        if ($pw === '') {
            return 0;
        }

        $len = mb_strlen($pw);
        $s = 0;
        if ($len >= 12) {
            $s++;
        }
        if ($len >= 16) {
            $s++;
        }
        if (preg_match('/[0-9]/', $pw) === 1 && preg_match('/[a-zA-Z]/', $pw) === 1) {
            $s++;
        }
        if (preg_match('/[^a-zA-Z0-9]/', $pw) === 1) {
            $s++;
        }
        if ($len < 8) {
            $s = min($s, 1);
        }

        return min($s, 4);
    }
}
