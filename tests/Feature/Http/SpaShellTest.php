<?php

use Illuminate\Support\Facades\Vite;

// Self-contained: the shell resolves its assets from a committed fixture manifest,
// so the suite passes without `npm run build` (public/build is gitignored).
beforeEach(function () {
    $this->originalPublicPath = app()->publicPath();
    app()->usePublicPath(base_path('tests/fixtures/vite'));
    Vite::useBuildDirectory('build')->useManifestFilename('manifest.json');
});

afterEach(function () {
    app()->usePublicPath($this->originalPublicPath);
});

it('serves the SPA shell on a deep link with a nonce on every script', function () {
    $response = $this->get('/domains/example.org/mailboxes?q=a');

    $response->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('<div id="app">');
    expect($html)->toContain('assets/main-fixture.js');
    expect($html)->toContain('<link rel="stylesheet"');

    preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
    expect($scripts[0])->not->toBeEmpty();

    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->not->toContain("'unsafe-inline'");
    foreach ($scripts[0] as $tag) {
        expect($tag)->toMatch('/nonce="([^"]+)"/');
        preg_match('/nonce="([^"]+)"/', $tag, $m);
        expect($csp)->toContain("script-src 'self' 'nonce-{$m[1]}'");
        expect($csp)->toContain("style-src 'self' 'nonce-{$m[1]}'");
    }

    expect($html)->toMatch('/<meta property="csp-nonce" nonce="[^"]+"/');
});

it('does not swallow API 404s into the shell', function () {
    $this->getJson('/api/v1/nope')
        ->assertNotFound()
        ->assertHeader('Content-Type', 'application/problem+json');
});

it('keeps the health route', function () {
    $this->get('/up')->assertOk();
});

it('serves the SPA shell for the emailed reset link', function () {
    $this->get('/reset-password/'.str_repeat('ab', 32).'?email=anna%40example.org')
        ->assertOk()
        ->assertSee('<div id="app">', false);
});
