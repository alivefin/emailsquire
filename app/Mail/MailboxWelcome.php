<?php

namespace App\Mail;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * A mailbox's setup instructions for the person who will use it (POST …/mailboxes/{mailbox}/welcome):
 * the address, which is also the username, the IMAP and SMTP servers with their ports and security,
 * and the webmail link when `emailsquire.webmail_url` is an http(s) URL. The recipient may have no
 * console account, so the header names the app without linking to it. It is informational: it carries no
 * password, hash or link that grants anything, so it needs no encryption on the queue. It carries the
 * address as stored in the guide, not the model, so a mailbox deleted before the worker runs still
 * gets the mail its sender asked for.
 *
 * Every value goes through `<x-mail::literal>`: an address such as `_anna_@example.org` must not
 * become Markdown emphasis.
 */
class MailboxWelcome extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const LOCALES = ['en', 'fi'];

    public const IMAP_PORT = 993;

    public const SMTP_PORT = 587;

    /** A Postfix restart must not lose the mail: three tries, 30 s and then 120 s apart (SendQueuedMailable reads both). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /** @param  string  $locale  `en` or `fi`: the language the sender chose for the recipient */
    public function __construct(public string $address, string $locale)
    {
        if (! in_array($locale, self::LOCALES, true)) {
            throw new InvalidArgumentException("Unknown welcome email locale '{$locale}'.");
        }
        $this->locale($locale);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.welcome.subject', ['address' => $this->address]));
    }

    public function content(): Content
    {
        $host = config()->string('emailsquire.mail_host');

        return new Content(
            markdown: 'mail.welcome',
            with: [
                'heading' => __('mail.welcome.subject', ['address' => $this->address]),
                'intro' => __('mail.welcome.intro', ['address' => $this->addressWithUnicode()]),
                'webmailUrl' => self::webmailUrl(),
                'username' => __('mail.welcome.username', ['address' => $this->address]),
                'imap' => __('mail.welcome.imap', ['server' => $host.':'.self::IMAP_PORT]),
                'smtp' => __('mail.welcome.smtp', ['server' => $host.':'.self::SMTP_PORT]),
            ],
        );
    }

    /**
     * `emailsquire.webmail_url` when it is an absolute http or https URL with a host; null when it is
     * empty or anything else (`javascript:`, a bare name), so the mail never links to it. The doctor's
     * mail.webmail_url check warns about such a value.
     */
    public static function webmailUrl(): ?string
    {
        $url = config('emailsquire.webmail_url');
        $url = is_string($url) ? trim($url) : '';
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return null;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $url : null;
    }

    /** The address, plus its domain in Unicode in parentheses when the domain is an IDN: `anna@xn--exmple-cua.fi (exämple.fi)`. */
    private function addressWithUnicode(): string
    {
        $at = strrpos($this->address, '@');
        if ($at === false) {
            return $this->address;
        }
        $domain = substr($this->address, $at + 1);
        try {
            $unicode = app(AddressNormalizer::class)->domain($domain)->unicode;
        } catch (InvalidAddress) {
            return $this->address;
        }

        return $unicode === $domain ? $this->address : "{$this->address} ({$unicode})";
    }
}
