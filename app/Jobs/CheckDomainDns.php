<?php

namespace App\Jobs;

use App\MailServer\Dkim;
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
     * Seconds before the worker gives up on it. DnsHealth asks nothing more after emailsquire.dns.budget
     * (30 s), and one last lookup may take about 30 s more, so a run ends by about 60 s. The database
     * queue's retry_after (150 s) is above this, so a second worker never picks it up meanwhile.
     */
    public int $timeout = 100;

    /**
     * How long the uniqueness lock lasts if the job never runs: the scheduler's interval, so a stopped
     * worker holds at most one waiting check per domain instead of one every 6 h.
     */
    public int $uniqueFor = 21600;

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
            // The DKIM helper's finished results first, so a key it just made is checked too.
            app(Dkim::class)->syncQueued($this->domainId, $this->domainName);
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
