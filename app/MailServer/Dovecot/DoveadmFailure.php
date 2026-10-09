<?php

namespace App\MailServer\Dovecot;

enum DoveadmFailure
{
    case Unreachable;
    case Unauthorized;
    case CommandError;
    case Malformed;
}
