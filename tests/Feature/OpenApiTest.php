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
        'post /domains/{domain}/mailboxes/{mailbox}/welcome' => [429],
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
        'put /portal/password' => [401, 403, 422, 429, 503],
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
    expect($login['anyOf'])->toHaveCount(3)
        ->and($login['anyOf'][0]['required'])->toContain('kind', 'two_factor', 'two_factor_required', 'locale')
        ->and($login['anyOf'][0]['properties']['kind']['enum'])->toBe(['console'])
        ->and($login['anyOf'][1])->toBe(['type' => 'object', 'properties' => ['kind' => ['type' => 'string', 'enum' => ['mailbox']]], 'required' => ['kind']])
        ->and($login['anyOf'][2]['properties']['two_factor']['enum'])->toBe([true]);
});

test('the setup answer is the me payload or the pending two-factor sign-in, as a console login is without its kind', function () {
    $paths = openApiSpec()['paths'];
    $setup = $paths['/auth/setup']['post']['responses']['200']['content']['application/json']['schema'];
    $login = $paths['/auth/login']['post']['responses']['200']['content']['application/json']['schema'];
    $console = $login['anyOf'][0];
    unset($console['properties']['kind']);
    $console['required'] = array_values(array_diff($console['required'], ['kind']));

    expect($setup['anyOf'])->toBe([$console, $login['anyOf'][2]])
        ->and(array_keys($paths['/auth/setup']['post']['responses']))->toBe([200, 403, 419, 422, 429]);
});

test('the portal routes document their 401', function () {
    $responses = openApiSpec()['paths']['/portal/me']['get']['responses'];

    expect(array_keys($responses))->toBe([200, 401])
        ->and($responses['401']['description'])->toContain('session_expired')
        ->and($responses['200']['content']['application/json']['schema']['required'])
        ->toBe(['address', 'domain', 'domain_unicode', 'quota_bytes', 'used_bytes', 'self_service', 'managers', 'support_contact', 'client_settings']);
});

test('the welcome email answers 202 Queued.', function () {
    $responses = openApiSpec()['paths']['/domains/{domain}/mailboxes/{mailbox}/welcome']['post']['responses'];

    expect($responses['202']['description'])->toBe('Queued.')
        ->and($responses['202'])->not->toHaveKey('content');
});

test('the login body documents the mailbox flag, and its 429 the per-IP mailbox cap', function () {
    $spec = openApiSpec();
    $login = $spec['paths']['/auth/login']['post'];
    $ref = $login['requestBody']['content']['application/json']['schema']['$ref'];
    $body = $spec['components']['schemas'][substr($ref, strlen('#/components/schemas/'))];

    expect($body['properties']['mailbox']['type'])->toBe('boolean')
        ->and($body['properties']['mailbox']['description'])->toContain('console')
        ->and($body['required'])->toBe(['email', 'password'])
        ->and($login['responses']['429']['description'])->toContain('30 a minute per IP address');
});
