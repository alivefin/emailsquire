<?php

namespace App\Doctor;

/**
 * One independent doctor check. Implementations are resolved from the
 * container and may throw: Doctor turns any Throwable into a Fail result.
 */
interface Check
{
    /** Stable dotted id, e.g. `guide.schema`. */
    public function id(): string;

    public function title(): string;

    /**
     * Whether the check needs the mail server itself (Postfix, Dovecot, files).
     * With `--require-server`, a Skip from a server check becomes a Fail.
     */
    public function isServerCheck(): bool;

    public function run(): CheckResult;
}
