<?php

namespace App\MailServer\Exceptions;

use RuntimeException;

/** An invitation link that is no longer valid (`expired`, `used` or `revoked`); rendered as 410 `invitation_invalid` with its `state`. */
class InvitationInvalid extends RuntimeException
{
    /** @param  'expired'|'used'|'revoked'|'valid'  $state */
    public function __construct(public readonly string $state)
    {
        parent::__construct("The invitation is {$state}.");
    }

    public function detail(): string
    {
        return trans()->string("emailsquire.invitation.state.{$this->state}");
    }
}
