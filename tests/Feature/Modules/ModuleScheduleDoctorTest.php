<?php

use App\Doctor\Doctor;
use App\Doctor\Status;
use Illuminate\Support\Facades\Artisan;

// Modules add scheduled work through the core scheduler and doctor checks through the core Doctor.

it('lists a module\'s scheduled entry', function () {
    bootWithModules(fixtureModule('example'));

    Artisan::call('schedule:list');

    expect(Artisan::output())->toContain('example:tick');
});

it('runs a module\'s doctor check after every core check', function () {
    bootWithModules(fixtureModule('example'));

    $results = app(Doctor::class)->run();
    $last = $results[array_key_last($results)];

    expect($results)->toHaveCount(count(Doctor::CHECKS) + 1)
        ->and($last->id)->toBe('example.ok')
        ->and($last->status)->toBe(Status::Ok);
});

it('adds no check or entry without modules', function () {
    expect(app(Doctor::class)->run())->toHaveCount(count(Doctor::CHECKS));

    Artisan::call('schedule:list');
    expect(Artisan::output())->not->toContain('example:tick');
});

it('turns a module check that cannot be resolved into a Fail and carries on', function () {
    bootWithModules(fixtureModule('broken-check').','.fixtureModule('example'));

    $results = app(Doctor::class)->run();
    $byId = collect($results)->keyBy('id');

    expect($results)->toHaveCount(count(Doctor::CHECKS) + 2)
        ->and($byId['ExampleBrokenCheck']->status)->toBe(Status::Fail)
        ->and($byId['ExampleBrokenCheck']->detail)->toContain('The broken check cannot be built.')
        ->and($byId['example.ok']->status)->toBe(Status::Ok);
});
