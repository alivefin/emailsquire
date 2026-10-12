<?php

namespace App\MailServer\Dovecot;

enum DoveadmFailure
{
    case Unreachable;
    case Unauthorized;
    case CommandUnauthorized;
    case CommandError;
    case Malformed;
}
