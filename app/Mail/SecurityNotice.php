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
use InvalidArgumentException;

/**
 * Tells a user that something about their account security changed. The sender sets the locale
 * (`Mail::to($user)->locale($user->locale)`), see Notify::security().
 */
class SecurityNotice extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public const KINDS = ['password_changed', 'password_reset', 'two_factor_disabled', 'passkey_added', 'passkey_removed', 'passkey_cloned', 'sessions_revoked', 'email_changed'];

    /** A user deleted before the worker runs must not end up in failed_jobs. */
    public bool $deleteWhenMissingModels = true;

    /** A Postfix restart must not lose the mail: three tries, 30 s and then 120 s apart (SendQueuedMailable reads both). */
    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public CarbonImmutable $at;

    public function __construct(
        public User $user,
        public string $kind,
        public ?string $ip,
        ?CarbonInterface $at = null,
    ) {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown security notice kind '{$kind}'.");
        }
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
