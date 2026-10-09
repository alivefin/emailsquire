<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');
});

it('passes the doctor with --require-server on a guide-built host', function () {
    $exit = Artisan::call('emailsquire:doctor', ['--require-server' => true, '--json' => true]);
    $results = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $failed = array_values(array_filter($results, fn (array $r) => $r['status'] === 'fail'));

    expect($failed)->toBe([])
        ->and($exit)->toBe(0);
});
