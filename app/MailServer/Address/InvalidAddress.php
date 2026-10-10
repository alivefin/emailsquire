<?php

namespace App\MailServer\Address;

use InvalidArgumentException;

final class InvalidAddress extends InvalidArgumentException
{
    /** @param 'empty'|'local_chars'|'domain_format'|'too_long'|'not_ascii' $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(__("emailsquire.address.{$reason}"));
    }
}
