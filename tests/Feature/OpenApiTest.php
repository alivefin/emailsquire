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
