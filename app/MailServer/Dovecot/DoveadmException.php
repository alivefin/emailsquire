<?php

namespace App\MailServer\Dovecot;

use RuntimeException;

class DoveadmException extends RuntimeException
{
    public function __construct(
        public readonly DoveadmFailure $reason,
        string $message,
        public readonly ?int $exitCode = null,
    ) {
        parent::__construct($message);
    }
}
