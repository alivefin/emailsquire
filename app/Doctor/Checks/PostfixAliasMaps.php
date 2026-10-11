<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Doctor\Support\AliasMaps;

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
        return match (AliasMaps::inspect(config('emailsquire.paths.postfix_main_cf'))) {
            AliasMaps::SUPPORTED => $this->result(Status::Ok, 'catch-all supported'),
            AliasMaps::NOT_FOUND => $this->result(Status::Skip, 'main.cf not found'),
            AliasMaps::UNREADABLE => $this->result(Status::Warn, 'catch-all aliases will be refused: cannot read main.cf or a virtual_alias_maps file as this user'),
            AliasMaps::MISSING => $this->result(Status::Warn, 'catch-all aliases will be refused: virtual_alias_maps has no email2email map'),
        };
    }
}
