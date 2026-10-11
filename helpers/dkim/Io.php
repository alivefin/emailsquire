<?php

namespace EmailSquire\Helpers\Dkim;

/** Runs one filesystem call and turns a failure into an I/O error that names the OS reason but no path. */
final class Io
{
    /**
     * Returns the call's result, or throws "$message: <reason>" when it returned false. A local error
     * handler catches the warning, so it never reaches the output, and strips PHP's "fopen(<path>): "
     * prefix and "Failed to open stream: ", leaving "Permission denied", "File exists" and the like.
     *
     * @template T
     *
     * @param  \Closure(): (T|false)  $call
     * @return T
     *
     * @throws \RuntimeException
     */
    public static function call(string $message, \Closure $call): mixed
    {
        $reason = null;
        set_error_handler(static function (int $level, string $text) use (&$reason): bool {
            $reason = preg_replace('/^\w+\(.*?\): (?:Failed to open stream: )?/s', '', $text);

            return true;
        });
        try {
            $result = $call();
        } finally {
            restore_error_handler();
        }
        if ($result === false) {
            throw new \RuntimeException($reason === null ? $message : "{$message}: {$reason}");
        }

        return $result;
    }
}
