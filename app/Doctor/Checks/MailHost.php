<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;

/**
 * The server name the welcome email gives mail apps (`EMAILSQUIRE_MAIL_HOST`, default this host's name)
 * is a full name with a dot, not an IP address. A bare `localhost` or hostname reaches nobody's
 * mail app, and an IP address fails the certificate check.
 */
final class MailHost extends BaseCheck
{
    public function id(): string
    {
        return 'mail.host';
    }

    public function title(): string
    {
        return 'Mail host in the welcome email';
    }

    public function run(): CheckResult
    {
        $host = config('emailsquire.mail_host');
        $host = is_string($host) ? $host : '';

        if (filter_var(trim($host, '[]'), FILTER_VALIDATE_IP) !== false) {
            return $this->result(Status::Warn, 'The welcome email names the mail host by its IP address “'.mb_substr($host, 0, 80).'”, '
                .'which the mail TLS certificate does not cover; set EMAILSQUIRE_MAIL_HOST to the name on the certificate, such as mail.example.org');
        }
        if (preg_match('/^[^.\s]+(\.[^.\s]+)+$/', $host) !== 1) {
            return $this->result(Status::Warn, 'The welcome email names the mail host “'.mb_substr($host, 0, 80).'”, '
                .'which mail apps cannot reach; set EMAILSQUIRE_MAIL_HOST to the name on the mail TLS certificate, such as mail.example.org');
        }

        return $this->result(Status::Ok, "mail_host is {$host}");
    }
}
