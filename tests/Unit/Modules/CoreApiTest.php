<?php

use App\Modules\CoreApi;

it('declares the core API version', function () {
    expect(CoreApi::VERSION)->toBe('1.0.0');
});

it('checks a core_api constraint against the version', function (string $constraint, bool $expected) {
    expect(CoreApi::satisfies($constraint))->toBe($expected);
})->with([
    ['^1.0', true],
    ['^1.0.0', true],
    ['^1.1', false],
    ['^1.0.1', false],
    ['^2.0', false],
    ['^0.9', false],
    ['1.0', false],
    ['>=1.0', false],
    ['~1.0', false],
    ['*', false],
    ['', false],
]);
