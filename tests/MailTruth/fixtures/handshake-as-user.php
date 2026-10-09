<?php

// Run by DovecotTest as an unprivileged user: prints the DovecotAuthFailure case of a handshake, or OK.

use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\DovecotAuthException;

require '/app/vendor/autoload.php';

try {
    (new DovecotAuthClient('/run/dovecot/emailsquire-auth', 10))->handshake();
    echo "OK\n";
} catch (DovecotAuthException $e) {
    echo $e->reason->name, "\n";
}
