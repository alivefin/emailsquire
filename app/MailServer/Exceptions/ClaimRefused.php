<?php

namespace App\MailServer\Exceptions;

use RuntimeException;

/** A name is already claimed elsewhere on the server; rendered as 422 against one field. */
class ClaimRefused extends RuntimeException
{
    public function __construct(public readonly string $errorCode, string $detail, public readonly string $field)
    {
        parent::__construct($detail);
    }
}
