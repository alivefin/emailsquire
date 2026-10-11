<?php

namespace App\MailServer\Dns;

use RuntimeException;

/**
 * A lookup that got no answer (SERVFAIL, a timeout, no resolver), as opposed to an answer with no
 * records (NXDOMAIN, NODATA), which is an empty list. DnsHealth turns it into a failed check.
 */
class DnsLookupFailed extends RuntimeException {}
