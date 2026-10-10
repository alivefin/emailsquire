<?php

namespace App\MailServer\Usage;

final readonly class UsageRefreshResult
{
    public function __construct(
        public int $checked,
        public int $skipped,
        public bool $complete,
        public ?string $error = null,
    ) {}
}
