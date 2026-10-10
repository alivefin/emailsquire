<?php

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\GuideSchema;
use App\MailServer\Repositories\BlockedAddressRepository;
use App\Models\AuditEntry;
use App\Models\BlockedAddress;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

function imaBlockJohn(): void
{
    app(BlockedAddressRepository::class)->block(app(AddressNormalizer::class)->address('john@example.org'), 'earlier');
}

function imaTableExists(): bool
{
    return DB::selectOne(
        'SELECT COUNT(*) AS c FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
        [GuideSchema::name(), 'blacklist_email'],
    )->c > 0;
}

it('says so when no blacklist_email table is visible', function () {
    GuideFixture::load('trixie');

    expect(Artisan::call('emailsquire:import-ima'))->toBe(0);
    expect(Artisan::output())
        ->toContain('No blacklist_email table is visible on the "'.DB::getDefaultConnection().'" connection')
        ->toContain('--database=migrator');
});

it('exits cleanly from --drop when there is no table', function () {
    GuideFixture::load('trixie');

    expect(Artisan::call('emailsquire:import-ima', ['--drop' => true]))->toBe(0);
    expect(Artisan::output())->toContain('No blacklist_email table');
});

it('refuses a --database that is not a configured connection', function () {
    GuideFixture::load('trixie', 'ima-blacklist');

    expect(Artisan::call('emailsquire:import-ima', ['--database' => 'no_such_connection']))->toBe(1)
        ->and(Artisan::call('emailsquire:import-ima', ['--database' => "x'; DROP"]))->toBe(1);
    expect(BlockedAddress::query()->count())->toBe(0);
});

it('imports IMA blacklist rows, decoding entities and normalising addresses', function () {
    GuideFixture::load('trixie', 'ima-blacklist');
    imaBlockJohn();
    AuditEntry::query()->delete();

    expect(Artisan::call('emailsquire:import-ima'))->toBe(0);
    expect(trim(Artisan::output()))->toContain('Imported 3, skipped 1 already blocked, refused 1: bad..x@example.org');

    expect(BlockedAddress::query()->where('address', 'old@example.org')->sole()->reason)->toBe('spam & abuse')
        ->and(BlockedAddress::query()->where('address', 'anna@example.org')->sole()->reason)->toBeNull()
        ->and(BlockedAddress::query()->where('address', 'spaced@example.org')->sole()->reason)->toBe('trailing space')
        ->and(BlockedAddress::query()->where('address', 'john@example.org')->sole()->reason)->toBe('earlier')
        ->and(BlockedAddress::query()->count())->toBe(4);

    $audit = AuditEntry::query()->where('action', 'blocked.create')->get();
    expect($audit)->toHaveCount(3)
        ->and($audit->pluck('actor_type')->unique()->all())->toBe(['cli']);
});

it('imports nothing new on a second run', function () {
    GuideFixture::load('trixie', 'ima-blacklist');
    imaBlockJohn();
    Artisan::call('emailsquire:import-ima');

    expect(Artisan::call('emailsquire:import-ima'))->toBe(0);
    expect(Artisan::output())->toContain('Imported 0, skipped 4 already blocked, refused 1: bad..x@example.org');
    expect(BlockedAddress::query()->count())->toBe(4);
});

it('keeps the table when --drop finds a refused row', function () {
    GuideFixture::load('trixie', 'ima-blacklist');

    expect(Artisan::call('emailsquire:import-ima', ['--drop' => true]))->toBe(1);
    expect(Artisan::output())->toContain('bad..x@example.org')->toContain('not dropped');
    expect(imaTableExists())->toBeTrue();
});

it('drops the table once every row is imported or skipped', function () {
    GuideFixture::load('trixie', 'ima-blacklist');
    DB::statement('DELETE FROM '.GuideSchema::table('blacklist_email').' WHERE id = 4');

    expect(Artisan::call('emailsquire:import-ima', ['--drop' => true]))->toBe(0);
    expect(Artisan::output())->toContain('Imported 4, skipped 0 already blocked, refused 0')
        ->toContain('Dropped '.GuideSchema::name().'.blacklist_email.');
    expect(imaTableExists())->toBeFalse()
        ->and(BlockedAddress::query()->count())->toBe(4);
});
