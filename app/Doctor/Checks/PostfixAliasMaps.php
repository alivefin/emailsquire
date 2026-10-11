<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Doctor\Support\AliasMaps;
use App\MailServer\CatchAllSupport;
use Illuminate\Support\Facades\Cache;

/** Whether catch-all forwarding is safe on this server; the console asks the same through CatchAllSupport. */
final class PostfixAliasMaps extends BaseCheck
{
    public const EMAIL2EMAIL_QUERY = AliasMaps::EMAIL2EMAIL_QUERY;

    public function id(): string
    {
        return 'postfix.alias_maps';
    }

    public function title(): string
    {
        return 'Postfix alias maps (catch-all)';
    }

    public function isServerCheck(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        // The console asks again on its next catch-all write, as its own user: a fix that doctor
        // confirms takes effect at once. This run's answer is not stored (doctor may run as root).
        Cache::forget(CatchAllSupport::CACHE_KEY);

        return match (AliasMaps::inspect(config('emailsquire.paths.postfix_main_cf'))) {
            AliasMaps::SUPPORTED => $this->result(Status::Ok, 'catch-all supported'),
            AliasMaps::NOT_FOUND => $this->result(Status::Skip, 'main.cf not found'),
            AliasMaps::UNREADABLE => $this->result(Status::Warn, 'catch-all aliases will be refused: cannot read main.cf or a virtual_alias_maps file as this user'),
            AliasMaps::MISSING => $this->result(Status::Warn, 'catch-all aliases will be refused: virtual_alias_maps has no email2email map'),
        };
    }
}
