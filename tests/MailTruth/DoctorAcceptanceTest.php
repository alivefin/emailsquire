<?php

use Illuminate\Support\Facades\Artisan;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');
});

it('passes the doctor with --require-server on a guide-built host, but for the test environment', function () {
    $exit = Artisan::call('emailsquire:doctor', ['--require-server' => true, '--json' => true]);
    $results = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

    $failed = array_values(array_filter($results, fn (array $r) => $r['status'] === 'fail'));

    // The suite itself runs with APP_ENV=testing on the host's real mail root (/var/vmail), which
    // runtime.test_environment exists to fail: on a deployed host it would mean the e2e stand-in for
    // Dovecot's auth could be live. Every other check passes.
    expect(array_column($failed, 'id'))->toBe(['runtime.test_environment'])
        ->and($exit)->not->toBe(0);
});
