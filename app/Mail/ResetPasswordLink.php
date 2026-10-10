<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The forgotten-password link, sent in the user's language (`Mail::to($user)->locale($user->locale)`).
 * The link is built from APP_URL, never from the request's Host header, so a forged Host can't send a
 * user's token elsewhere. The SPA serves /reset-password/{token}. Encrypted on the queue (APP_KEY), so
 * the token in the link never sits readable in `jobs` or `failed_jobs`.
 */
class ResetPasswordLink extends Mailable implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable, SerializesModels;

    /** A user deleted before the worker runs must not end up in failed_jobs. */
    public bool $deleteWhenMissingModels = true;

    /** A Postfix restart must not lose the mail: three tries, 30 s and then 120 s apart (SendQueuedMailable reads both). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public string $url;

    public function __construct(public User $user, string $token)
    {
        $this->url = rtrim(config()->string('app.url'), '/').'/reset-password/'.rawurlencode($token)
            .'?email='.rawurlencode($user->email);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.reset.subject'));
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.reset-password',
            with: [
                'url' => $this->url,
                'minutes' => config()->integer('auth.passwords.users.expire'),
            ],
        );
    }
}
