<?php

namespace App\Jobs;

use App\MailServer\Dns\DnsHealth;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * One domain's DNS health check (DnsHealth::run()), queued by the scheduler every 6 h and by an
 * on-demand recheck. One per domain at a time: a second dispatch while one waits is dropped.
 *
 * It carries the domain's id and name, and skips a domain that was deleted, or whose id now carries
 * another name, since it was queued.
 */
class CheckDomainDns implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    /**
     * Seconds before the worker gives up on it: below the database queue's retry_after (90 s), so a
     * stuck lookup never makes a second worker run it too. DnsHealth stops asking after
     * emailsquire.dns.budget seconds, well before this.
     */
    public int $timeout = 80;

    /** How long the uniqueness lock lasts if the job never ends. */
    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $domainId,
        public readonly string $domainName,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->domainId;
    }

    public function handle(DnsHealth $dns): void
    {
        try {
            $dns->runQueued($this->domainId, $this->domainName);
        } finally {
            $dns->forgetQueued($this->domainId);
        }
    }

    public function failed(?Throwable $e): void
    {
        app(DnsHealth::class)->forgetQueued($this->domainId);
    }
}
