<?php

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\EmailAddress;
use App\MailServer\AddressClaims;
use App\MailServer\Exceptions\ClaimRefused;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\HelperTasks;
use App\Models\BlockedAddress;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\GuideFixture;

uses(DatabaseTruncation::class);

beforeEach(function () {
    GuideFixture::load('trixie');
});

function claimsAddress(string $local): EmailAddress
{
    $n = app(AddressNormalizer::class);

    return $n->addressIn($local, $n->domain('example.org'));
}

function claimsRefusal(callable $fn): ClaimRefused
{
    try {
        $fn();
    } catch (ClaimRefused $e) {
        return $e;
    }
    throw new RuntimeException('Expected ClaimRefused.');
}

it('reports status with blocked > mailbox > forwarding > archiving > available', function () {
    $claims = app(AddressClaims::class);
    expect($claims->status(claimsAddress('fresh')))->toBe('available');
    expect($claims->status(claimsAddress('john')))->toBe('mailbox');
    expect($claims->status(claimsAddress('jack')))->toBe('forwarding');

    app(HelperTasks::class)->queueArchive(claimsAddress('john'));
    expect($claims->status(claimsAddress('john')))->toBe('mailbox');

    BlockedAddress::query()->create(['address' => 'john@example.org', 'reason' => null]);
    expect($claims->status(claimsAddress('john')))->toBe('blocked');
});

it('refuses a mailbox on a blocked address, with and without a reason', function () {
    BlockedAddress::query()->create(['address' => 'a@example.org', 'reason' => 'abuse']);
    BlockedAddress::query()->create(['address' => 'b@example.org', 'reason' => null]);
    $claims = app(AddressClaims::class);

    $e = claimsRefusal(fn () => $claims->assertCanCreateMailbox(claimsAddress('a')));
    expect($e->errorCode)->toBe('blocked')->and($e->field)->toBe('local')
        ->and($e->getMessage())->toBe('a@example.org is blocked — reason: “abuse”.');

    $e = claimsRefusal(fn () => $claims->assertCanCreateMailbox(claimsAddress('b')));
    expect($e->getMessage())->toBe('b@example.org is blocked.');
});

it('refuses a mailbox where a mailbox or forwarding already exists', function () {
    $claims = app(AddressClaims::class);

    $e = claimsRefusal(fn () => $claims->assertCanCreateMailbox(claimsAddress('john')));
    expect($e->errorCode)->toBe('exists_mailbox')->and($e->getMessage())->toBe('john@example.org already exists.');

    $e = claimsRefusal(fn () => $claims->assertCanCreateMailbox(claimsAddress('jack')));
    expect($e->errorCode)->toBe('exists_forwarding')
        ->and($e->getMessage())->toBe('jack@example.org already forwards somewhere — remove that first.');
});

it('throws a 409 Conflict while the address is being archived', function () {
    app(HelperTasks::class)->queueArchive(claimsAddress('gone'));
    $claims = app(AddressClaims::class);

    $expected = 'gone@example.org was deleted recently and its mail is still being archived. Try again in a few minutes.';
    expect(fn () => $claims->assertCanCreateMailbox(claimsAddress('gone')))
        ->toThrow(Conflict::class, $expected);
    expect(fn () => $claims->assertCanForward(claimsAddress('gone'), true, false))
        ->toThrow(Conflict::class, $expected);
    try {
        $claims->assertCanCreateMailbox(claimsAddress('gone'));
    } catch (Conflict $e) {
        expect($e->errorCode)->toBe('archiving');
    }
});

it('refuses forwarding from a blocked source', function () {
    BlockedAddress::query()->create(['address' => 'a@example.org', 'reason' => 'abuse']);

    $e = claimsRefusal(fn () => app(AddressClaims::class)->assertCanForward(claimsAddress('a'), true, false));
    expect($e->errorCode)->toBe('blocked');
});

it('lets a mailbox source forward with keep_copy, and otherwise needs confirmation', function () {
    $claims = app(AddressClaims::class);

    $claims->assertCanForward(claimsAddress('john'), true, false);
    $claims->assertCanForward(claimsAddress('john'), false, true);
    $claims->assertCanForward(claimsAddress('jack'), false, false);
    $claims->assertCanForward(claimsAddress('fresh'), false, false);

    $e = claimsRefusal(fn () => $claims->assertCanForward(claimsAddress('john'), false, false));
    expect($e->errorCode)->toBe('redirect_not_confirmed')->and($e->field)->toBe('redirect_mailbox')
        ->and($e->getMessage())->toBe('john@example.org is a mailbox. Confirm that its mail should be forwarded only.');
});
