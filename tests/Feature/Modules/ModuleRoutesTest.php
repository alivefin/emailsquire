<?php

use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureSameOrigin;
use App\Http\Middleware\RequireTwoFactor;
use App\MailServer\Dovecot\PasswordChecker;
use App\MailServer\GuideSchema;
use App\Models\User;
use App\Modules\ModuleRoutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Process\Process;
use Tests\Support\FakePasswordChecker;
use Tests\Support\GuideFixture;

// Module APIs live under /api/v1/modules/<name>; the core, not the module, applies the console middleware.

beforeEach(fn () => bootWithModules(fixtureModule('example')));

it('answers 401 problem+json without a session', function () {
    $this->getJson('/api/v1/modules/example/ping')
        ->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated');
});

it('serves a signed-in console user', function (bool $admin) {
    $this->actingAs(User::factory()->create(['is_server_admin' => $admin, 'password_set_at' => now()]));

    $this->getJson('/api/v1/modules/example/ping')->assertOk()->assertExactJson(['module' => 'example', 'admin' => $admin]);
})->with([true, false]);

it('answers 401 to a mailbox session', function () {
    // The application was refreshed after the suite's beforeEach bound its fake.
    app()->instance(PasswordChecker::class, (new FakePasswordChecker)->accept('anna@example.org', 'mailbox passphrase 2'));
    GuideFixture::load('trixie');
    DB::table(GuideSchema::table('virtual_users'))->insert(['domain_id' => 10, 'email' => 'anna@example.org', 'password' => '{BLF-CRYPT}x', 'quota' => 0]);
    $this->postJson('/api/v1/auth/login', ['email' => 'anna@example.org', 'password' => 'mailbox passphrase 2', 'mailbox' => true])->assertOk();

    $this->getJson('/api/v1/modules/example/ping')->assertUnauthorized()->assertHeader('Content-Type', 'application/problem+json');
});

it('answers 401 to a deactivated user', function () {
    $this->actingAs(User::factory()->create(['status' => 'deactivated', 'password_set_at' => now()]));

    $this->getJson('/api/v1/modules/example/ping')->assertUnauthorized();
});

it('gives every module route the console middleware, in order', function () {
    expect(ModuleRoutes::MIDDLEWARE)->toBe([
        'web',
        EnsureSameOrigin::class,
        'auth:web',
        EnsureActiveUser::class,
        AbsoluteSessionLimit::class,
        RequireTwoFactor::class,
    ]);

    $found = 0;
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'api/v1/modules/')) {
            $found++;
            expect(array_values(array_intersect($route->gatherMiddleware(), ModuleRoutes::MIDDLEWARE)))->toBe(ModuleRoutes::MIDDLEWARE);
        }
    }
    expect($found)->toBeGreaterThan(0);
});

/**
 * Runs `php artisan emailsquire:openapi` in a fresh process with the example module listed, as a
 * module's CI would: Scramble keeps state between two exports in one process.
 *
 * @param  list<string>  $arguments
 * @return array{int, string} the exit code and the output
 */
function exportOpenApi(array $arguments): array
{
    $process = new Process([PHP_BINARY, base_path('artisan'), 'emailsquire:openapi', ...$arguments], base_path(), ['EMAILSQUIRE_MODULES' => fixtureModule('example')]);
    $process->run();

    return [$process->getExitCode() ?? 1, $process->getOutput().$process->getErrorOutput()];
}

function openApiTempPath(): string
{
    return sys_get_temp_dir().'/es-openapi-'.bin2hex(random_bytes(6)).'.json';
}

it('keeps module routes out of the core OpenAPI document', function () {
    $path = openApiTempPath();
    try {
        [$code] = exportOpenApi([$path]);

        expect($code)->toBe(0)->and(File::get($path))->toBe(File::get(base_path('resources/js/api/openapi.json')));
    } finally {
        File::delete($path);
    }
});

it('exports one module\'s document with --module', function () {
    $path = openApiTempPath();
    try {
        [$code] = exportOpenApi(['--module=example', "--output={$path}"]);
        $spec = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);

        expect($code)->toBe(0)->and(array_keys($spec['paths']))->toBe(['/modules/example/ping', '/modules/example/notes']);
    } finally {
        File::delete($path);
    }
});

it('refuses to export an unknown module', function () {
    $path = openApiTempPath();

    [$code, $output] = exportOpenApi(['--module=nope', "--output={$path}"]);

    expect($code)->toBe(1)->and($output)->toContain('nope')->and(file_exists($path))->toBeFalse();
});
