<?php

use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');
});

it('delivers mail for an alias to its destination mailbox', function () {
    $before = deliveredCount('john');

    sendMail('jack@example.org');

    expect(deliveredCount('john', $before + 1))->toBe($before + 1);
});

it('delivers to every destination of a keep-a-copy alias', function () {
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'john@example.org'],
        ['domain_id' => 10, 'source' => 'john@example.org', 'destination' => 'mary@example.org'],
    ]);
    $john = deliveredCount('john');
    $mary = deliveredCount('mary');

    sendMail('john@example.org');

    expect(deliveredCount('john', $john + 1))->toBe($john + 1)
        ->and(deliveredCount('mary', $mary + 1))->toBe($mary + 1);
});
