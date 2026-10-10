<?php

namespace App\Mail;

use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Tells a user that something about their account security changed. The sender sets the locale
 * (`Mail::to($user)->locale($user->locale)`), see Notify::security().
 */
class SecurityNotice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const KINDS = ['password_changed', 'password_reset', 'two_factor_disabled', 'sessions_revoked'];

    public CarbonImmutable $at;

    public function __construct(
        public User $user,
        public string $kind,
        public ?string $ip,
        ?CarbonInterface $at = null,
    ) {
        $this->at = CarbonImmutable::instance($at ?? now());
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: __("mail.security.{$this->kind}.subject"));
    }

    public function content(): Content
    {
        $locale = app()->getLocale();

        return new Content(
            markdown: 'mail.security-notice',
            with: [
                'heading' => __("mail.security.{$this->kind}.subject"),
                'what' => __("mail.security.{$this->kind}.what"),
                'time' => $this->at
                    ->settings(['locale' => $locale])
                    ->setTimezone(config()->string('app.timezone'))
                    ->isoFormat('LL LT (z)'),
                'from' => $this->ip === null || $this->ip === ''
                    ? __('mail.security.from_unknown')
                    : __('mail.security.from_ip', ['ip' => $this->ip]),
            ],
        );
    }
}
