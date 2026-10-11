<?php

namespace App\Support;

/** A file path read from the environment in config/: what an operator's `.env` line means. */
final class ConfigPath
{
    /**
     * $value, or $default when it is unset or blank (an empty `KEY=` line means "not set", not the base
     * directory). A relative path is taken from the app's base directory.
     */
    public static function resolve(mixed $value, string $default): string
    {
        $path = is_string($value) ? trim($value) : '';
        if ($path === '') {
            $path = $default;
        }

        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
