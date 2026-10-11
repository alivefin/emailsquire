<?php

use App\Models\User;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');

    $this->actingAs(User::factory()->create(['is_server_admin' => true, 'name' => 'Mail Truth Admin']));
});

/**
 * The messages in a mailbox's Maildir/new, read whole. Delivery is asynchronous (Postfix, then Dovecot's
 * LMTP), so it polls for up to $seconds until at least one message is there.
 *
 * @return list<string>
 */
function waitForMaildirMessages(string $local, int $seconds): array
{
    $dir = "/var/vmail/example.org/{$local}/Maildir/new";
    $deadline = microtime(true) + $seconds;

    do {
        $files = is_dir($dir) ? array_values(array_diff((array) scandir($dir), ['.', '..'])) : [];
        if ($files !== []) {
            return array_map(fn (string $f): string => (string) file_get_contents("{$dir}/{$f}"), $files);
        }
        usleep(250_000);
    } while (microtime(true) < $deadline);

    return [];
}

it('delivers an invitation mail through the local Postfix into the invitee\'s Maildir', function () {
    // The app's own SMTP mailer (config/mail.php: 127.0.0.1:25, no STARTTLS), not phpunit's array mailer.
    config(['mail.default' => 'smtp']);

    $local = apiLocal('invitee');
    createApiMailbox($this, $local);

    confirmStepUp();
    $this->postJson('/api/v1/domains/example.org/access/invitations', [
        'email' => "{$local}@example.org",
        'role' => 'viewer',
        'note' => 'Delivered for real.',
        'locale' => 'en',
    ])->assertCreated();

    $messages = waitForMaildirMessages($local, 30);

    expect($messages)->toHaveCount(1);

    // Bodies may be quoted-printable (soft line breaks inside the link), headers MIME-encoded.
    $raw = $messages[0];
    $body = quoted_printable_decode($raw);
    preg_match('/^Subject: (.+(?:\r?\n[ \t].+)*)/m', $raw, $subject);

    expect(iconv_mime_decode($subject[1] ?? '', 0, 'UTF-8'))->toBe('You’re invited to EmailSquire')
        ->and($body)->toMatch('~/invite#[A-Za-z0-9_-]{43}~')
        ->and($body)->toContain('Mail Truth Admin')
        ->and($body)->toContain('example.org')
        ->and($body)->toContain('Delivered for real.')
        // The token travels in the fragment only, never as a query parameter a server would log.
        ->and($body)->not->toMatch('~/invite\?~');
});
