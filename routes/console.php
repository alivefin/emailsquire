<?php

use App\MailServer\Dns\DnsHealth;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('emailsquire:refresh-usage')->everyFifteenMinutes()->withoutOverlapping(30);

// Every domain's DNS health: a queued check per domain (CheckDomainDns). Not audited; only on-demand
// rechecks are (domain.dns_checked).
Schedule::call(fn (DnsHealth $dns) => $dns->queueAll())->everySixHours()->name('emailsquire:dns-checks')->withoutOverlapping(30);
