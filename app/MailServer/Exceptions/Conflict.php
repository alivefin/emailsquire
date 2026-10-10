<?php

namespace App\MailServer\Exceptions;

use RuntimeException;

/** The request is valid but clashes with current state; rendered as 409. */
class Conflict extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $detail)
    {
        parent::__construct($detail);
    }
}
