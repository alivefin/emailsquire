<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The forgotten-password link, sent in the user's language (`Mail::to($user)->locale($user->locale)`).
 * The link is built from APP_URL, never from the request's Host header, so a forged Host can't send a
 * user's token elsewhere. The SPA serves /reset-password/{token}.
 */
class ResetPasswordLink extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** A user deleted before the worker runs must not end up in failed_jobs. */
    public bool $deleteWhenMissingModels = true;

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
