<?php

namespace App\MailServer\Address;

final readonly class DomainName
{
    public function __construct(public string $ascii, public string $unicode) {}
}
