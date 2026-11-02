<?php

use App\Modules\CoreApi;

it('declares the core API version', function () {
    expect(CoreApi::VERSION)->toBe('1.1.0');
});

it('checks a core_api constraint against the version', function (string $constraint, bool $expected) {
    expect(CoreApi::satisfies($constraint))->toBe($expected);
})->with([
    ['^1.0', true],
    ['^1.0.0', true],
    ['^1.1', true],
    ['^1.1.0', true],
    ['^1.0.1', true],
    ['^1.1.1', false],
    ['^1.2', false],
    ['^2.0', false],
    ['^0.9', false],
    ['1.0', false],
    ['>=1.0', false],
    ['~1.0', false],
    ['*', false],
    ['', false],
    ["^1.0\n", false],
    ["^1.0.0\n", false],
]);

it('is the version the SPA host object declares', function () {
    // resources/js/modules/host.ts states it twice: in the Host type and in the object installHost() builds.
    $host = (string) file_get_contents(dirname(__DIR__, 3).'/resources/js/modules/host.ts');
    preg_match_all("/apiVersion: '([^']*)'/", $host, $m);

    expect($m[1])->toHaveCount(2)->each->toBe(CoreApi::VERSION);
});
