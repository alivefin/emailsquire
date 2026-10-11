<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Mail\MailboxWelcome;

/**
 * The webmail address the welcome email links to (`EMAILSQUIRE_WEBMAIL_URL`) is an http or https URL
 * when it is set. The mail leaves any other value out (MailboxWelcome::webmailUrl()), so a typo means
 * a welcome email without its webmail link.
 */
final class MailWebmailUrl extends BaseCheck
{
    public function id(): string
    {
        return 'mail.webmail_url';
    }

    public function title(): string
    {
        return 'Webmail link in the welcome email';
    }

    public function run(): CheckResult
    {
        $url = config('emailsquire.webmail_url');
        $url = is_string($url) ? trim($url) : '';

        if ($url === '') {
            return $this->result(Status::Ok, 'webmail_url is not set; the welcome email has no webmail link');
        }
        if (MailboxWelcome::webmailUrl() === null) {
            return $this->result(Status::Warn, 'EMAILSQUIRE_WEBMAIL_URL is “'.mb_substr($url, 0, 80).'”, which is not an http or https URL; '
                .'the welcome email leaves it out. Set it to an address such as https://webmail.example.org/');
        }

        return $this->result(Status::Ok, "webmail_url is {$url}");
    }
}
