<?php

use Tests\Support\AuthorizationMatrix as Matrix;

// GET /me lists the loaded modules with the navigation and dashboard cards each user may see.

beforeEach(function () {
    config([
        'emailsquire.modules_root' => 'tests/fixtures/modules',
        'emailsquire.modules_require_root_owner' => false,
    ]);
});

it('lists no modules when none is loaded', function () {
    $this->actingAs(Matrix::seed()['admin']);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('modules', []);
});

it('gives a server admin the example module with its navigation and card', function () {
    bootWithModules(fixtureModule('example'));
    $this->actingAs(Matrix::seed()['admin']);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('modules', [[
        'name' => 'example',
        'version' => '1.0.0',
        'route' => '/example',
        'nav' => [['key' => 'notes', 'path' => '/example', 'label_key' => 'example:nav.notes']],
        'dashboard_cards' => ['summary'],
    ]]);
});

it('gives a domain manager and a viewer the module with no navigation', function (string $role) {
    bootWithModules(fixtureModule('example'));
    $this->actingAs(Matrix::seed()[$role]);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('modules', [[
        'name' => 'example',
        'version' => '1.0.0',
        'route' => '/example',
        'nav' => [],
        'dashboard_cards' => ['summary'],
    ]]);
})->with(['manager', 'viewer']);

it('leaves a refused module out', function () {
    bootWithModules(fixtureModule('bad-api'));
    $this->actingAs(Matrix::seed()['admin']);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('modules', []);
});
