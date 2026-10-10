<?php

use Illuminate\Support\Facades\Http;
use Tests\Support\AuthorizationMatrix as Matrix;

// One case per route and principal, each against a.org (granted to the manager and the viewer) and
// b.org (granted to nobody), on a fresh world. The rows live in Tests\Support\AuthorizationMatrix.

beforeEach(function () {
    $this->principals = Matrix::seed();
    // doveadm answers every call: live usage reads a quota, kicks and the rest succeed.
    fakeDoveadmBatch(fn (string $command) => $command === 'quotaGet'
        ? ['doveadmResponse', [['type' => 'STORAGE', 'value' => '1024', 'limit' => '5242880']]]
        : ['doveadmResponse', []]);
});

it('answers every route as the role matrix says', function (string $route, string $principal) {
    $row = Matrix::routes()[$route];
    [$method, $uri] = explode(' ', $route, 2);
    $this->actingAs($this->principals[$principal]);
    // Step-up comes after can:, so a confirmed session shows the authorization answer on step-up routes too.
    confirmStepUp();

    foreach (Matrix::DOMAINS as $i => $domain) {
        $expected = $row['expect'][$principal][$i];
        $response = $this->json($method, Matrix::url($uri, $domain, $row), Matrix::body($row, $domain));

        expect($response->status())->toBe($expected, "{$route} as {$principal} on {$domain}: ".$response->getContent());
        if ($expected === 403) {
            expect($response->json('code'))->toBe('forbidden');
        }
        if ($expected === 404) {
            expect($response->json('code'))->toBe('not_found');
        }
    }
})->with(Matrix::cases());

it('an out-of-scope domain answers byte for byte like a domain that does not exist', function () {
    $this->actingAs($this->principals['manager']);

    foreach (['GET domains/{domain}/mailboxes', 'GET domains/{domain}/forwarding', 'PUT me/pinned-domains/{domain}', 'DELETE domains/{domain}'] as $route) {
        [$method, $uri] = explode(' ', $route, 2);
        $ungranted = $this->json($method, Matrix::url($uri, 'b.org', []));
        $missing = $this->json($method, Matrix::url($uri, 'nowhere.org', []));

        expect($ungranted->status())->toBe(404)
            ->and($ungranted->getContent())->toBe($missing->getContent());
    }
    Http::assertNothingSent();
});
