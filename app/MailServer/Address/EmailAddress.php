<?php

namespace App\MailServer\Address;

final readonly class EmailAddress
{
    public function __construct(public string $local, public DomainName $domain) {}

    public function address(): string
    {
        return $this->local.'@'.$this->domain->ascii;
    }
}
