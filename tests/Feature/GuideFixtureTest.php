<?php

use Illuminate\Support\Facades\DB;
use Tests\Support\GuideFixture;

it('loads the trixie fixture into the guide schema only', function () {
    GuideFixture::load('trixie');

    expect(guideCount('virtual_domains'))->toBe(1)
        ->and(guideCount('virtual_users'))->toBe(2)
        ->and(guideCount('virtual_aliases'))->toBe(1)
        ->and(DB::select('select database() as db')[0]->db)->toBe('emailsquire_test');
});

it('loads the restored-bookworm shape without the unique key', function () {
    GuideFixture::load('restored-bookworm');

    $unique = DB::select(
        "select index_name from information_schema.STATISTICS
         where table_schema = ? and table_name = 'virtual_domains'
           and column_name = 'name' and non_unique = 0",
        [config('emailsquire.guide_schema')],
    );

    expect($unique)->toBeEmpty()
        ->and(guideCount('virtual_users'))->toBe(2);
});

it('layers anomalies on top of trixie', function () {
    GuideFixture::load('trixie', 'anomalies');

    expect(guideCount('virtual_users'))->toBe(7)
        ->and(guideCount('virtual_aliases'))->toBe(6)
        ->and(DB::select('select database() as db')[0]->db)->toBe('emailsquire_test');
});
