<?php

namespace App\Mail;

use App\Auth\Invitations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;

/**
 * An invitation to EmailSquire (App\Auth\Invitations), sent in the invitation's language. The link is
 * built from APP_URL by the caller, never from the request's Host header; the SPA serves /invite and
 * reads the token from the fragment (/invite#{token}), which browsers never send to a server. Encrypted on the queue (APP_KEY), so the token never sits readable in `jobs` or
 * `failed_jobs`. It carries values, not models: the invitee has no account yet.
 *
 * The inviter's note and both names are typed by people, so the view prints them through
 * `<x-mail::literal>`: escaped HTML that the Markdown pass leaves alone, and plain text in the text part.
 */
class InvitationMail extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    /** A Postfix restart must not lose the mail: three tries, 30 s and then 120 s apart (SendQueuedMailable reads both). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    /**
     * @param  string  $role  `server_admin`, `manager` or `viewer`
     * @param  string|null  $domainUnicode  null for a server-admin invitation
     */
    public function __construct(
        public string $url,
        public string $inviterName,
        public ?string $recipientName,
        public string $role,
        public ?string $domainUnicode,
        public ?string $note,
    ) {
        if (! in_array($role, ['server_admin', 'manager', 'viewer'], true)) {
            throw new InvalidArgumentException("Unknown invitation role '{$role}'.");
        }
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.invitation.subject'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invitation',
            with: [
                'greeting' => $this->recipientName === null || $this->recipientName === ''
                    ? __('mail.invitation.greeting')
                    : __('mail.invitation.greeting_name', ['name' => $this->recipientName]),
                'intro' => __('mail.invitation.intro', ['inviter' => $this->inviterName]),
                'access' => $this->domainUnicode === null
                    ? __('mail.invitation.access_server')
                    : __('mail.invitation.access_domain', ['domain' => $this->domainUnicode]),
                'roleLine' => __('mail.invitation.role', ['role' => __("mail.invitation.roles.{$this->role}")]),
                'noteHeading' => __('mail.invitation.note', ['inviter' => $this->inviterName]),
                'note' => $this->note === null || trim($this->note) === '' ? null : $this->note,
                'url' => $this->url,
                'hours' => Invitations::TTL_HOURS,
            ],
        );
    }
}
