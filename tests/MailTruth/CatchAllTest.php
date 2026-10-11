<?php

use App\MailServer\GuideSchema;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

/*
 * Catch-all forwarding on the trixie host, whose Postfix has the guide's email2email map in
 * virtual_alias_maps: the console allows the catch-all (App\MailServer\CatchAllSupport reads the real
 * main.cf), Postfix delivers mail for unknown addresses to its target, and every existing mailbox and
 * alias keeps its own mail, because email2email answers a mailbox with itself before the catch-all.
 */

beforeEach(function () {
    GuideFixture::load('trixie');

    $this->actingAs(User::factory()->create(['is_server_admin' => true]));
});

/** Whether Postfix accepts RCPT TO $to, read through the SMTP dialogue. */
function catchAllRcptAccepted(string $to): bool
{
    $process = new Process(['swaks', '--server', 'localhost', '--to', $to, '--from', 'sender@example.net', '--quit-after', 'RCPT']);
    $process->run();

    return $process->isSuccessful();
}

it('delivers mail for unknown addresses to the catch-all target, while mailboxes and aliases keep their own mail', function () {
    $nobody = apiLocal('nobody').'@example.org';
    expect(catchAllRcptAccepted($nobody))->toBeFalse();

    $this->putJson('/api/v1/domains/example.org/forwarding/@', ['targets' => ['john@example.org']])->assertCreated();
    expect(postmapQuery('@example.org', 'virtual-alias-maps'))->toBe('john@example.org')
        ->and(postmapQuery('mary@example.org', 'email2email'))->toBe('mary@example.org');

    // An address with no mailbox and no forwarding goes to john.
    $john = deliveredCount('john');
    sendMail($nobody);
    expect(deliveredCount('john', $john + 1))->toBe($john + 1);

    // mary still gets her own mail, and john nothing more (one LMTP delivery would have carried both).
    $mary = deliveredCount('mary');
    sendMail('mary@example.org');
    expect(deliveredCount('mary', $mary + 1))->toBe($mary + 1);
    sleep(1);
    expect(deliveredCount('john'))->toBe($john + 1);

    // The fixture's alias jack -> john still delivers once, through its own row.
    sendMail('jack@example.org');
    expect(deliveredCount('john', $john + 2))->toBe($john + 2);
    sleep(1);
    expect(deliveredCount('john'))->toBe($john + 2)
        ->and(deliveredCount('mary'))->toBe($mary + 1);

    // Removed: unknown addresses are refused again.
    $this->deleteJson('/api/v1/domains/example.org/forwarding/@')->assertNoContent();
    expect(catchAllRcptAccepted($nobody))->toBeFalse()
        ->and(catchAllRcptAccepted('mary@example.org'))->toBeTrue();
});

it('delivers once round a loop that passes through a mailbox keeping a copy, as the console allows', function () {
    // mary@ keeps a copy and forwards to sales@; sales@ then forwards back to mary@. Postfix marks mary@
    // "been here" when she expands to herself, so the second time round it stops at her and delivers once
    // (AliasExpansion's walk accepts this circle, and refuses one without such an address).
    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        ['domain_id' => 10, 'source' => 'mary@example.org', 'destination' => 'mary@example.org'],
        ['domain_id' => 10, 'source' => 'mary@example.org', 'destination' => 'sales@example.org'],
    ]);
    $this->putJson('/api/v1/domains/example.org/forwarding/sales', ['targets' => ['mary@example.org']])->assertCreated();

    $mary = deliveredCount('mary');
    sendMail('sales@example.org');
    expect(deliveredCount('mary', $mary + 1))->toBe($mary + 1);
    sleep(1);
    expect(deliveredCount('mary'))->toBe($mary + 1);
});
