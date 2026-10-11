<?php

use App\Mail\MailboxWelcome;
use App\MailServer\AuditActions;
use App\MailServer\GuideSchema;
use App\Models\AuditEntry;
use App\Models\DomainGrant;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Support\AuthorizationMatrix;
use Tests\Support\GuideFixture;

// The welcome email (plan 2d, Task 2): a mailbox's setup instructions, never its password, sent to an
// address the manager types. The trixie fixture's example.org (id 10) has the mailbox john@; the
// manager and the viewer have a role on it, the stranger has none.

beforeEach(function () {
    config([
        'emailsquire.require_two_factor' => 'off',
        'emailsquire.mail_host' => 'mail.example.org',
        'emailsquire.webmail_url' => 'https://webmail.example.org/',
    ]);
    GuideFixture::load('trixie');

    $this->manager = User::factory()->create(['email' => 'mia@console.test', 'password_set_at' => now()]);
    $this->viewer = User::factory()->create(['email' => 'vic@console.test', 'password_set_at' => now()]);
    $this->stranger = User::factory()->create(['email' => 'sam@console.test', 'password_set_at' => now()]);
    foreach (['manager' => $this->manager, 'viewer' => $this->viewer] as $role => $user) {
        DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => 10, 'domain_name' => 'example.org', 'role' => $role]);
    }
});

function welcomeSend(array $body = [], ?User $as = null)
{
    test()->actingAs($as ?? test()->manager);

    return test()->postJson('/api/v1/domains/example.org/mailboxes/john/welcome', array_merge([
        'to' => 'john.private@example.net',
        'locale' => 'en',
    ], $body));
}

/** @return array{0: string, 1: string} the rendered HTML and text parts */
function welcomeParts(MailboxWelcome $mail): array
{
    [$html, $text] = (fn () => $this->renderForAssertions())->call($mail);

    return [(string) $html, (string) $text];
}

it('queues the mail to the typed address in the requested locale and answers 202 with no body', function () {
    Mail::fake();

    $response = welcomeSend(['to' => 'john.private@example.net', 'locale' => 'fi']);

    $response->assertStatus(202);
    expect($response->getContent())->toBe('');
    Mail::assertQueued(MailboxWelcome::class, 1);
    Mail::assertQueued(MailboxWelcome::class, function (MailboxWelcome $mail) {
        [$html, $text] = welcomeParts($mail);

        return $mail->hasTo('john.private@example.net')
            && $mail->locale === 'fi'
            && $mail->address === 'john@example.org'
            && str_contains($html, 'Postilaatikkosi john@example.org on valmis')
            && str_contains($text, 'Postilaatikkosi john@example.org on valmis');
    });
});

it('is queued with three tries and localises the subject', function () {
    $mail = new MailboxWelcome('john@example.org', 'en');

    expect($mail)->toBeInstanceOf(ShouldQueue::class)
        ->and($mail->tries)->toBe(3)
        ->and($mail->backoff)->toBe([30, 120])
        ->and($mail->locale)->toBe('en');

    // The subject is resolved in the mail's own locale, as when the worker sends it.
    welcomeParts($mail);
    expect($mail->subject)->toBe('Your mailbox john@example.org is ready');
    $fi = new MailboxWelcome('john@example.org', 'fi');
    welcomeParts($fi);
    expect($fi->subject)->toBe('Postilaatikkosi john@example.org on valmis');
});

it('refuses a locale other than en or fi', function () {
    new MailboxWelcome('john@example.org', 'de');
})->throws(InvalidArgumentException::class);

it('writes the address, the servers with their ports and security, and the webmail link in both parts', function (string $locale) {
    [$html, $text] = welcomeParts(new MailboxWelcome('john@example.org', $locale));

    foreach ([$html, $text] as $part) {
        expect($part)->toContain('john@example.org')
            ->toContain('mail.example.org:993')
            ->toContain('SSL/TLS')
            ->toContain('mail.example.org:587')
            ->toContain('STARTTLS')
            ->toContain('https://webmail.example.org/')
            ->not->toContain('mail.welcome.');
    }
    // IMAP goes with SSL/TLS and SMTP with STARTTLS, on the same line.
    expect($text)->toMatch('/mail\.example\.org:993[^\n]*SSL\/TLS/')
        ->toMatch('/mail\.example\.org:587[^\n]*STARTTLS/');
})->with(['en', 'fi']);

it('tells the reader the administrator gives the password', function () {
    [$html, $text] = welcomeParts(new MailboxWelcome('john@example.org', 'en'));

    expect($html)->toContain('Your administrator will give you your password.')
        ->and($text)->toContain('Your administrator will give you your password.');

    [$html] = welcomeParts(new MailboxWelcome('john@example.org', 'fi'));
    expect($html)->toContain('Saat salasanan ylläpitäjältäsi.');
});

it('leaves the webmail line out when webmail_url is empty', function () {
    config(['emailsquire.webmail_url' => '']);

    [$html, $text] = welcomeParts(new MailboxWelcome('john@example.org', 'en'));

    foreach ([$html, $text] as $part) {
        expect($part)->not->toContain('webmail.example.org')
            ->not->toContain('Webmail')
            ->toContain('mail.example.org:993');
    }
});

it('prints an address with underscores as it is, not as Markdown emphasis', function () {
    [$html, $text] = welcomeParts(new MailboxWelcome('_anna_b_@example.org', 'en'));

    expect($html)->toContain('_anna_b_@example.org')->not->toContain('<em>')
        ->and($text)->toContain('_anna_b_@example.org');
});

it('contains no password, no hash and no external image', function () {
    Mail::fake();
    $hash = DB::table(GuideSchema::table('virtual_users'))->where('email', 'john@example.org')->value('password');
    expect($hash)->toStartWith('{BLF-CRYPT}');

    welcomeSend()->assertStatus(202);

    Mail::assertQueued(MailboxWelcome::class, function (MailboxWelcome $mail) use ($hash) {
        [$html, $text] = welcomeParts($mail);
        foreach ([$html, $text] as $part) {
            expect($part)->not->toContain($hash)
                ->not->toContain(substr($hash, strlen('{BLF-CRYPT}')))
                ->not->toContain('{BLF-CRYPT}')
                ->not->toContain('$2y$')
                ->not->toContain('summersun')
                ->not->toContain('reset-password');
        }
        expect($html)->not->toMatch('/<img\b[^>]*\bsrc\s*=\s*["\']?\s*(https?:)?\/\//i')
            ->not->toContain('<img');

        return true;
    });
});

it('refuses a display name, a list, header injection and an overlong address', function (string $to) {
    Mail::fake();

    welcomeSend(['to' => $to])
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonValidationErrors('to');

    Mail::assertNothingQueued();
    expect(AuditEntry::count())->toBe(0);
})->with([
    'display name' => 'Anna <a@b.c>',
    'angle brackets' => '<a@b.c>',
    'comma list' => 'a@b.c, d@e.f',
    'comma list without space' => 'a@b.c,d@e.f',
    'semicolon list' => 'a@b.c;d@e.f',
    'crlf bcc' => "a@b.c\r\nBcc: x@y.z",
    'lf bcc' => "a@b.c\nBcc: x@y.z",
    'quoted local part with a space' => '"a b"@b.c',
    'comment' => 'a(comment)@b.c',
    'group syntax' => 'list:a@b.c;',
    'tab' => "a\t@b.c",
    'not an address' => 'anna',
    // 255 characters that email:rfc accepts: only the length check refuses it.
    'over 254 characters' => str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 60).'.org',
    'empty' => '',
]);

it('refuses a missing or unknown locale', function (mixed $locale) {
    Mail::fake();

    welcomeSend(['locale' => $locale])->assertStatus(422)->assertJsonValidationErrors('locale');

    Mail::assertNothingQueued();
})->with(['de', 'EN', '', null]);

it('accepts an address of exactly 254 characters', function () {
    Mail::fake();
    $to = str_repeat('a', 64).'@'.str_repeat('b', 62).'.'.str_repeat('c', 62).'.'.str_repeat('d', 59).'.org';
    expect(strlen($to))->toBe(254);

    welcomeSend(['to' => $to])->assertStatus(202);

    Mail::assertQueued(MailboxWelcome::class, fn (MailboxWelcome $mail) => $mail->hasTo($to));
});

it('keeps the local part as typed, trims and lowercases the domain', function () {
    Mail::fake();

    welcomeSend(['to' => '  John.Private@Example.NET  '])->assertStatus(202);

    Mail::assertQueued(MailboxWelcome::class, fn (MailboxWelcome $mail) => $mail->hasTo('John.Private@example.net'));
    expect(AuditEntry::sole()->changes)->toBe(['to' => 'John.Private@example.net']);
});

it('audits mailbox.welcome_sent with the recipient and the domain', function () {
    Mail::fake();

    welcomeSend()->assertStatus(202);

    $row = AuditEntry::sole();
    expect($row->action)->toBe('mailbox.welcome_sent')
        ->and($row->object_type)->toBe('mailbox')
        ->and($row->object_key)->toBe('john@example.org')
        ->and($row->domain_name)->toBe('example.org')
        ->and($row->actor_id)->toBe((string) $this->manager->id)
        ->and($row->changes)->toBe(['to' => 'john.private@example.net'])
        ->and(AuditActions::group('mailboxes'))->toContain('mailbox.welcome_sent');
});

it('refuses a viewer with 403 and a stranger with 404, and sends nothing', function () {
    Mail::fake();

    welcomeSend(as: $this->viewer)->assertStatus(403)->assertJsonPath('code', 'forbidden');
    welcomeSend(as: $this->stranger)->assertStatus(404)->assertJsonPath('code', 'not_found');
    test()->actingAs($this->manager)->postJson('/api/v1/domains/example.org/mailboxes/nobody/welcome', ['to' => 'a@b.c', 'locale' => 'en'])
        ->assertStatus(404);

    Mail::assertNothingQueued();
    expect(AuditEntry::count())->toBe(0);
});

it('is in the role matrix: managers and admins send, viewers 403, strangers 404', function () {
    $row = AuthorizationMatrix::routes()['POST domains/{domain}/mailboxes/{mailbox}/welcome'] ?? null;

    expect($row)->not->toBeNull()
        ->and($row['expect'])->toBe(['admin' => [202, 202], 'manager' => [202, 404], 'viewer' => [403, 404], 'none' => [404, 404]]);
});

it('answers the 11th send in an hour from one user with 429', function () {
    Mail::fake();

    for ($i = 1; $i <= 10; $i++) {
        welcomeSend(['to' => "n{$i}@example.net"])->assertStatus(202);
    }
    welcomeSend(['to' => 'n11@example.net'])->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    Mail::assertQueued(MailboxWelcome::class, 10);
    expect(AuditEntry::count())->toBe(10);

    // Another user has their own ten.
    $other = User::factory()->create(['password_set_at' => now()]);
    DomainGrant::query()->forceCreate(['user_id' => $other->id, 'domain_id' => 10, 'domain_name' => 'example.org', 'role' => 'manager']);
    welcomeSend(as: $other)->assertStatus(202);

    // The window is an hour.
    $this->travel(61)->minutes();
    welcomeSend(['to' => 'n12@example.net'])->assertStatus(202);
});
