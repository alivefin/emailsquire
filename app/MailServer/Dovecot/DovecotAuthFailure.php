<?php

namespace App\MailServer\Dovecot;

enum DovecotAuthFailure
{
    case Unreachable;
    case PermissionDenied;
    case Timeout;
    case Protocol;
}
