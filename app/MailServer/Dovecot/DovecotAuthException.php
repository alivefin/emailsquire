<?php

namespace App\MailServer\Dovecot;

use RuntimeException;

class DovecotAuthException extends RuntimeException
{
    public function __construct(
        public readonly DovecotAuthFailure $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
