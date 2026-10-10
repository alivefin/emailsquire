<?php

namespace App\MailServer\Address;

use InvalidArgumentException;

final class InvalidAddress extends InvalidArgumentException
{
    public const MESSAGES = [
        'empty' => 'Enter an address.',
        'local_chars' => 'Only letters, numbers, dots, hyphens and underscores — no spaces.',
        'domain_format' => 'That doesn’t look like a domain name.',
        'too_long' => 'Addresses can be at most 100 characters and domains 50.',
        'not_ascii' => 'Use letters a–z in the part before @.',
    ];

    /** @param 'empty'|'local_chars'|'domain_format'|'too_long'|'not_ascii' $reason */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }
}
