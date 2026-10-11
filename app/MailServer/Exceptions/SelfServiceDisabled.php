<?php

namespace App\MailServer\Exceptions;

use RuntimeException;

/**
 * The domain does not let its mailbox owners make this change in the portal (a self-service switch is
 * off); rendered as 403 `self_service_disabled`, worded in the request's language.
 */
class SelfServiceDisabled extends RuntimeException
{
    /** @param  'forwarding'|'aliases'  $switch */
    public function __construct(public readonly string $switch)
    {
        parent::__construct("Self-service switch {$switch} is off.");
    }
}
