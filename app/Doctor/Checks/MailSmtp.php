<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\SmtpProbe;
use App\Doctor\Status;

/** The SMTP server the app sends through (the local Postfix) answers with a 220 greeting. */
final class MailSmtp extends BaseCheck
{
    public function __construct(private readonly SmtpProbe $probe) {}

    public function id(): string
    {
        return 'mail.smtp';
    }

    public function title(): string
    {
        return 'SMTP server for outgoing mail';
    }

    public function isServerCheck(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $host = config()->string('mail.mailers.smtp.host');
        $port = config()->integer('mail.mailers.smtp.port');

        $banner = $this->probe->banner($host, $port, 3.0);

        return match (true) {
            $banner === null => $this->result(Status::Fail, "No SMTP greeting from {$host}:{$port}. Is Postfix running?"),
            str_starts_with($banner, '220') => $this->result(Status::Ok, "{$host}:{$port} greets with 220"),
            default => $this->result(Status::Warn, "{$host}:{$port} greeted with “".mb_substr($banner, 0, 80).'” instead of 220'),
        };
    }
}
