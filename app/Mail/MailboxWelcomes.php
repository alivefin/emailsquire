<?php

namespace App\Mail;

use App\MailServer\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/** Sends a mailbox's setup instructions (MailboxWelcome) and audits it. */
final class MailboxWelcomes
{
    public function __construct(private AuditLog $audit) {}

    /**
     * Queues the welcome email for the mailbox $address to $to in $locale and audits
     * `mailbox.welcome_sent` with `changes: {to}` under $domainName. A rollback drops both the row
     * and the mail; with afterCommit() the mail is pushed only after the commit, so the guarantee
     * is no mail without its row.
     *
     * @param  'en'|'fi'  $locale
     */
    public function send(string $address, string $domainName, string $to, string $locale): void
    {
        $mail = (new MailboxWelcome($address, $locale))->afterCommit();

        DB::transaction(function () use ($address, $domainName, $to, $mail): void {
            $this->audit->record('mailbox.welcome_sent', 'mailbox', $address, $domainName, ['to' => $to]);
            Mail::to($to)->queue($mail);
        });
    }
}
