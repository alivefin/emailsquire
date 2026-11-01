<?php

namespace App\Modules;

/** The version of the API that modules build against. */
final class CoreApi
{
    public const string VERSION = '1.0.0';

    /**
     * Whether a manifest's `core_api` constraint accepts this core. Only
     * `^MAJOR.MINOR` and `^MAJOR.MINOR.PATCH` are understood: same major, and the
     * core's minor.patch at least the stated one.
     */
    public static function satisfies(string $constraint): bool
    {
        if (preg_match('/^\^(\d+)\.(\d+)(?:\.(\d+))?$/', $constraint, $m) !== 1) {
            return false;
        }

        [$major, $minor, $patch] = array_map(intval(...), explode('.', self::VERSION));
        $wanted = [(int) $m[1], (int) $m[2], (int) ($m[3] ?? 0)];

        return $wanted[0] === $major && [$minor, $patch] >= [$wanted[1], $wanted[2]];
    }
}
