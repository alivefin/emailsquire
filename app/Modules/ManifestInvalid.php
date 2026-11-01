<?php

namespace App\Modules;

use RuntimeException;

/**
 * A module's manifest cannot be used. `reason` is the stable refusal code
 * (`manifest_missing` or `manifest_invalid`); the message is the detail.
 */
final class ManifestInvalid extends RuntimeException
{
    public function __construct(public readonly string $reason, string $detail)
    {
        parent::__construct($detail);
    }
}
