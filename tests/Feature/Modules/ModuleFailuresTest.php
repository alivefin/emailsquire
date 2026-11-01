<?php

use App\Doctor\Doctor;
use App\Doctor\Status;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Tests\Support\AuthorizationMatrix as Matrix;

// A module that loaded can still throw, or answer nonsense, when the core calls it later. Each call is
// guarded per module: the failure is logged, that module's contribution is skipped, and the console,
// Artisan and doctor carry on with the others.

it('lists a module whose navigation and cards throw with neither, beside a healthy one', function () {
    bootWithModules(fixtureModule('misbehaves').','.fixtureModule('example'));
    Log::spy();
    $this->actingAs(Matrix::seed()['admin']);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('modules', [
        ['name' => 'misbehaves', 'version' => '1.0.0', 'route' => '/misbehaves', 'nav' => [], 'dashboard_cards' => []],
        [
            'name' => 'example',
            'version' => '1.0.0',
            'route' => '/example',
            'nav' => [['key' => 'notes', 'path' => '/example', 'label_key' => 'example:nav.notes']],
            'dashboard_cards' => ['summary'],
        ],
    ]);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'misbehaves')
        && str_contains($message, 'The misbehaves fixture has no navigation().'));
});

it('skips navigation items and cards of the wrong type', function () {
    bootWithModules(fixtureModule('odd-values'));
    Log::spy();
    $this->actingAs(Matrix::seed()['admin']);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('modules.0.nav', [
        ['key' => 'ok', 'path' => '/odd-values', 'label_key' => 'odd-values:nav.ok'],
    ])->assertJsonPath('modules.0.dashboard_cards', ['ok']);

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message) => str_contains($message, 'odd-values'));
});

it('keeps Artisan and the schedule working when a module\'s schedule() throws', function () {
    // Booting loads routes/console.php, which calls schedule(): it must not throw.
    bootWithModules(fixtureModule('misbehaves').','.fixtureModule('example'));

    expect(Artisan::call('schedule:list'))->toBe(0)
        ->and(Artisan::output())->toContain('example:tick');
});

it('keeps every php artisan invocation working when a module\'s schedule() throws', function () {
    // A fresh process, as the scheduler, the queue worker and an operator run it; nothing is logged to disk.
    $process = new Process([PHP_BINARY, base_path('artisan'), 'schedule:list'], base_path(), [
        'EMAILSQUIRE_MODULES' => fixtureModule('misbehaves').','.fixtureModule('example'),
        'LOG_CHANNEL' => 'null',
    ]);
    $process->run();

    expect($process->getExitCode())->toBe(0, $process->getErrorOutput())
        ->and($process->getOutput())->toContain('example:tick');
});

it('runs every check and fails the module whose doctorChecks() throws', function () {
    bootWithModules(fixtureModule('misbehaves').','.fixtureModule('example'));

    $results = app(Doctor::class)->run();
    $byId = collect($results)->keyBy('id');

    expect($results)->toHaveCount(count(Doctor::CHECKS) + 2)
        ->and($byId['modules.misbehaves.checks']->status)->toBe(Status::Fail)
        ->and($byId['modules.misbehaves.checks']->title)->toContain('misbehaves')
        ->and($byId['modules.misbehaves.checks']->detail)->toContain('The misbehaves fixture has no doctorChecks().')
        ->and($byId['example.ok']->status)->toBe(Status::Ok)
        ->and($results[array_key_last($results)]->id)->toBe('example.ok');
});

it('fails a module whose doctorChecks() names what is not a class, once', function () {
    bootWithModules(fixtureModule('odd-values'));

    $results = app(Doctor::class)->run();
    $last = $results[array_key_last($results)];

    expect($results)->toHaveCount(count(Doctor::CHECKS) + 1)
        ->and($last->id)->toBe('modules.odd-values.checks')
        ->and($last->status)->toBe(Status::Fail)
        ->and($last->detail)->toContain('NoSuchCheck')->toContain('int');
});

it('boots and serves the other modules\' routes when a module\'s routes fail', function (string $broken) {
    bootWithModules(fixtureModule($broken).','.fixtureModule('example'));
    $this->actingAs(Matrix::seed()['admin']);

    $this->get('/up')->assertOk();
    $this->getJson('/api/v1/modules/example/ping')->assertOk()->assertExactJson(['module' => 'example', 'admin' => true]);
    $this->getJson("/api/v1/modules/{$broken}/ping")->assertNotFound();
})->with([
    'routes() throws' => 'misbehaves',
    'routes file missing' => 'odd-values',
    'routes file does not parse' => 'bad-routes',
    'routes file throws inside its own group' => 'throwing-routes',
]);
