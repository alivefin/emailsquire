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

    /**
     * What went wrong, for the log: a timeout (Dovecot is up but slow or stuck) apart from a socket that
     * can't be reached at all. Never names the address or the password.
     */
    public function summary(): string
    {
        return match ($this->reason) {
            DovecotAuthFailure::Timeout => 'Dovecot auth timed out',
            DovecotAuthFailure::Unreachable, DovecotAuthFailure::PermissionDenied => 'the Dovecot auth socket could not be reached',
            DovecotAuthFailure::Protocol => 'Dovecot auth answered unexpectedly',
        };
    }
}
