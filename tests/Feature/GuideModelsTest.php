<?php

use App\MailServer\GuideSchema;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

beforeEach(function () {
    GuideFixture::load('trixie');
});

it('reads guide rows through the models', function () {
    expect(Mailbox::find(1)->domain->name)->toBe('example.org');
    expect(Domain::find(10)->aliases)->toHaveCount(1);
    expect(Domain::find(10)->mailboxes)->toHaveCount(2);
    expect(Alias::find(1)->domain->name)->toBe('example.org');
});

it('never serialises the password', function () {
    expect(Mailbox::find(1)->toArray())->not->toHaveKey('password');
});

it('refuses mass assignment', function () {
    Mailbox::create(['domain_id' => 10, 'email' => 'x@example.org', 'password' => 'x']);
})->throws(MassAssignmentException::class);

it('rolls back guide and app writes together', function () {
    try {
        DB::transaction(function () {
            DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 99, 'name' => 'rollback.test']);
            DB::table('domain_settings')->insert(['domain_id' => 99, 'domain_name' => 'rollback.test']);

            throw new RuntimeException('boom');
        });
    } catch (RuntimeException) {
    }

    expect(DB::table(GuideSchema::table('virtual_domains'))->where('id', 99)->exists())->toBeFalse();
    expect(DB::table('domain_settings')->where('domain_id', 99)->exists())->toBeFalse();
});
