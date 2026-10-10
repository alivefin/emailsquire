<?php

use App\Mail\Notify;
use App\Mail\SecurityNotice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;

it('queues one security notice to the user in their locale', function (string $locale, string $subject) {
    Mail::fake();
    $user = User::factory()->create(['locale' => $locale]);

    Notify::security($user, 'password_changed');

    Mail::assertQueued(SecurityNotice::class, 1);
    Mail::assertQueued(SecurityNotice::class, function (SecurityNotice $mail) use ($user, $locale, $subject) {
        return $mail->hasTo($user->email)
            && $mail->locale === $locale
            && $mail->kind === 'password_changed'
            && __('mail.security.password_changed.subject', [], $locale) === $subject;
    });
})->with([
    ['fi', 'Salasanasi vaihdettiin'],
    ['en', 'Your password was changed'],
]);

it('is queued, not sent in the request', function () {
    expect(new SecurityNotice(User::factory()->make(), 'password_reset', '203.0.113.9'))
        ->toBeInstanceOf(ShouldQueue::class);
});

it('has a subject and body for every kind in both languages', function (string $kind, string $locale) {
    $user = User::factory()->create(['locale' => $locale]);
    $mail = (new SecurityNotice($user, $kind, '203.0.113.9'))->locale($locale);

    $html = $mail->render();

    expect($mail->envelope()->subject)->not->toContain('mail.')
        ->and($html)->not->toContain('mail.security')
        ->and($html)->toContain('203.0.113.9');
})->with(['password_changed', 'password_reset', 'two_factor_disabled', 'sessions_revoked'])
    ->with(['en', 'fi']);

it('renders without images or foreign URLs and has a text part', function () {
    $user = User::factory()->create(['locale' => 'fi']);
    $mail = (new SecurityNotice($user, 'two_factor_disabled', '203.0.113.9'))->locale('fi');

    $html = $mail->render();

    expect($html)->not->toContain('<img')
        ->and($html)->toContain('Jos tämä et ollut sinä');

    preg_match_all('#https?://[^\s"\'<>)]+#', $html, $matches);
    foreach ($matches[0] as $url) {
        expect($url)->toStartWith(config('app.url'));
    }

    $mail->assertSeeInText('203.0.113.9')->assertDontSeeInText('<');
});

it('shows the time in the recipient locale and the app time zone', function () {
    config()->set('app.timezone', 'Europe/Helsinki');
    $user = User::factory()->create(['locale' => 'fi']);
    $at = CarbonImmutable::parse('2026-10-10 12:30:00', 'UTC');

    $html = (new SecurityNotice($user, 'password_changed', null, $at))->locale('fi')->render();

    expect($html)->toContain('10. lokakuuta 2026')->toContain('15.30 (EEST)');
});

it('uses the configured sender and the local Postfix defaults', function () {
    expect(config('mail.from.address'))->toBe(config('emailsquire.mail_from'))
        ->and(config('emailsquire.mail_from'))->toStartWith('emailsquire@')
        ->and(config('mail.mailers.smtp.host'))->toBe('127.0.0.1')
        ->and(config('mail.mailers.smtp.port'))->toBe(25)
        ->and(config('mail.mailers.smtp.auto_tls'))->toBeFalse();
});
