<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

function openApiSpec(): array
{
    return json_decode((string) file_get_contents(base_path('resources/js/api/openapi.json')), true, 512, JSON_THROW_ON_ERROR);
}

test('the exported spec lists every api route', function () {
    $paths = openApiSpec()['paths'];
    $missing = [];
    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();
        if (! str_starts_with($uri, 'api/v1/') || $uri === 'api/v1/auth/csrf') {
            continue;
        }
        $path = '/'.substr($uri, strlen('api/v1/'));
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            if (! isset($paths[$path][strtolower($method)])) {
                $missing[] = "$method $path";
            }
        }
    }

    expect($missing)->toBe([]);
});

test('MailboxResource is fully typed in the spec', function () {
    $spec = openApiSpec();
    $keys = array_keys($spec['components']['schemas']['MailboxResource']['properties']);
    sort($keys);
    $expected = ['local', 'address', 'display_name', 'quota_bytes', 'quota_is_default', 'status', 'used_bytes', 'usage_checked_at', 'forwarding_count'];
    sort($expected);
    expect($keys)->toBe($expected);

    foreach ($spec['components']['schemas'] as $name => $schema) {
        if ($name === 'MailboxResource') {
            continue;
        }
        expect(array_keys($schema['properties'] ?? []))->not->toContain('password', "schema $name");
    }
    foreach ($spec['paths'] as $path => $methods) {
        foreach ($methods as $method => $op) {
            $json = json_encode($op['responses'] ?? []);
            if (str_contains($json, '"password"') && ! str_ends_with($path, '/password')) {
                $this->fail("$method $path exposes password in a response");
            }
        }
    }
});

test('the committed spec is current', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'openapi');
    Artisan::call('emailsquire:openapi', ['path' => $tmp]);
    $fresh = file_get_contents($tmp);
    unlink($tmp);

    expect($fresh)->toBe(file_get_contents(base_path('resources/js/api/openapi.json')));
});

test('the spec documents the conflict, origin and throttle problems the API really returns', function () {
    $paths = openApiSpec()['paths'];
    $expected = [
        'post /domains' => [409],
        'post /blocked-addresses' => [409],
        'post /domains/{domain}/mailboxes' => [409],
        'patch /domains/{domain}/mailboxes/{mailbox}' => [409],
        'delete /domains/{domain}/mailboxes/{mailbox}' => [409],
        'post /domains/{domain}/mailboxes/{mailbox}/password' => [409],
        'post /domains/{domain}/mailboxes/{mailbox}/disable' => [409],
        'post /domains/{domain}/mailboxes/{mailbox}/enable' => [409],
        'post /domains/{domain}/mailboxes/bulk' => [409],
        'put /domains/{domain}/forwarding/{local}' => [409],
        'delete /domains/{domain}/forwarding/{local}' => [409],
        'post /auth/login' => [403, 422, 429],
        'post /auth/setup' => [403, 422, 429],
        'post /auth/logout' => [401, 403],
        'post /auth/confirm-password' => [401, 403, 422, 429],
        'delete /domains/{domain}' => [423],
        'patch /domains/{domain}/access/{user}' => [409, 423],
        'delete /domains/{domain}/access/{user}' => [423],
        'post /auth/two-factor-challenge' => [403, 422, 429],
        'post /auth/forgot-password' => [403, 422, 429],
        'post /auth/reset-password' => [403, 422, 429],
        'post /auth/logout-all' => [401, 403, 429],
        'put /me/password' => [401, 403, 422, 429],
        'post /me/two-factor' => [401, 409, 423],
        'get /me/two-factor/setup' => [401, 404, 409, 423],
        'post /me/two-factor/confirm' => [401, 404, 409, 422, 423],
        'delete /me/two-factor' => [401, 423],
        'get /me/recovery-codes' => [401, 404, 423],
        'post /me/recovery-codes' => [401, 404, 423],
        'get /dashboard' => [403],
    ];

    $missing = [];
    foreach ($expected as $operation => $codes) {
        [$method, $path] = explode(' ', $operation, 2);
        foreach ($codes as $code) {
            $response = $paths[$path][$method]['responses'][(string) $code] ?? null;
            if (($response['content']['application/problem+json']['schema']['$ref'] ?? null) !== '#/components/schemas/Problem') {
                $missing[] = "{$operation} {$code}";
            }
        }
    }

    expect($missing)->toBe([]);
});

test('every state-changing operation documents the 419 csrf problem', function () {
    $missing = [];
    foreach (openApiSpec()['paths'] as $path => $operations) {
        foreach ($operations as $method => $operation) {
            if (! in_array($method, ['post', 'put', 'patch', 'delete'], true)) {
                continue;
            }
            $ref = $operation['responses']['419']['content']['application/problem+json']['schema']['$ref'] ?? null;
            if ($ref !== '#/components/schemas/Problem') {
                $missing[] = "{$method} {$path}";
            }
        }
    }

    expect($missing)->toBe([]);
});

test('every step-up route documents the 423 step_up_required problem', function () {
    $paths = openApiSpec()['paths'];
    $stepUp = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route) => in_array('step-up', $route->gatherMiddleware(), true));
    expect($stepUp)->not->toBeEmpty();

    $missing = [];
    foreach ($stepUp as $route) {
        $path = '/'.substr($route->uri(), strlen('api/v1/'));
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $response = $paths[$path][strtolower($method)]['responses']['423'] ?? null;
            if (($response['content']['application/problem+json']['schema']['$ref'] ?? null) !== '#/components/schemas/Problem'
                || ! str_contains($response['description'] ?? '', 'step_up_required')) {
                $missing[] = "{$method} {$path}";
            }
        }
    }

    expect($missing)->toBe([]);
});

test('the spec documents two_factor_required on gated routes only, and the two-factor login answer', function () {
    $paths = openApiSpec()['paths'];

    expect($paths['/dashboard']['get']['responses']['403']['description'])->toContain('two_factor_required')
        ->and($paths['/domains/{domain}']['delete']['responses']['403']['description'])->toContain('two_factor_required');
    foreach (['/me' => 'get', '/me/two-factor/setup' => 'get', '/me/recovery-codes' => 'get', '/auth/confirm-password' => 'post'] as $path => $method) {
        expect($paths[$path][$method]['responses']['403']['description'] ?? '')->not->toContain('two_factor_required');
    }

    $login = $paths['/auth/login']['post']['responses']['200']['content']['application/json']['schema'];
    expect($login['anyOf'])->toHaveCount(2)
        ->and($login['anyOf'][0]['required'])->toContain('two_factor', 'two_factor_required', 'locale')
        ->and($login['anyOf'][1]['properties']['two_factor']['enum'])->toBe([true]);
});

test('the setup answer is the me payload or the pending two-factor sign-in, as for login', function () {
    $paths = openApiSpec()['paths'];
    $setup = $paths['/auth/setup']['post']['responses']['200']['content']['application/json']['schema'];
    $login = $paths['/auth/login']['post']['responses']['200']['content']['application/json']['schema'];

    expect($setup)->toBe($login)
        ->and(array_keys($paths['/auth/setup']['post']['responses']))->toBe([200, 403, 419, 422, 429]);
});
