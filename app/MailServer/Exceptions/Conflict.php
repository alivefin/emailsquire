<?php

namespace App\MailServer\Exceptions;

use RuntimeException;

/**
 * The request is valid but clashes with current state; rendered as 409. $retryAfter, for a conflict
 * that passes with time, adds `retry_after` (seconds) to the body and a Retry-After header.
 */
class Conflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $detail, public readonly ?int $retryAfter = null)
    {
        parent::__construct($detail);
    }
}
