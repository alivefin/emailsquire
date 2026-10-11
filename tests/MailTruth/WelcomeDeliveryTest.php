<?php

use App\Models\User;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');

    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
});

it('delivers a mailbox\'s setup instructions through the local Postfix into the recipient\'s Maildir', function () {
    // The app's own SMTP mailer (config/mail.php: 127.0.0.1:25, no STARTTLS), not phpunit's array mailer.
    // The queue is sync here, so the queued mailable goes out within the request.
    config([
        'mail.default' => 'smtp',
        'emailsquire.mail_host' => 'mail.example.org',
        'emailsquire.webmail_url' => 'https://webmail.example.org/',
    ]);

    // The new mailbox, and the address its user reads mail at today.
    $local = apiLocal('welcome');
    $recipient = apiLocal('reader');
    createApiMailbox($this, $local);
    createApiMailbox($this, $recipient);

    $this->postJson("/api/v1/domains/example.org/mailboxes/{$local}/welcome", [
        'to' => "{$recipient}@example.org",
        'locale' => 'en',
    ])->assertStatus(202);

    $messages = waitForMaildirMessages($recipient, 30);

    expect($messages)->toHaveCount(1);

    // Bodies may be quoted-printable, headers MIME-encoded.
    $raw = $messages[0];
    $body = quoted_printable_decode($raw);
    preg_match('/^Subject: (.+(?:\r?\n[ \t].+)*)/m', $raw, $subject);

    expect(iconv_mime_decode($subject[1] ?? '', 0, 'UTF-8'))->toBe("Your mailbox {$local}@example.org is ready")
        ->and($body)->toContain("Username: {$local}@example.org")
        ->and($body)->toContain('Incoming mail (IMAP): mail.example.org:993, SSL/TLS')
        ->and($body)->toContain('Outgoing mail (SMTP): mail.example.org:587, STARTTLS')
        ->and($body)->toContain('https://webmail.example.org/')
        ->and($body)->toContain('Your administrator will give you your password.')
        // Neither the password nor its hash travels.
        ->and($body)->not->toContain(API_TEST_PASSWORD)
        ->and($body)->not->toContain('{BLF-CRYPT}');
});
