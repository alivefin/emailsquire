<?php

it('serves the SPA shell on a deep link with a nonce on every script', function () {
    $response = $this->get('/domains/example.org/mailboxes?q=a');

    $response->assertOk();
    $html = $response->getContent();

    expect($html)->toContain('<div id="app">');

    preg_match_all('/<script\b[^>]*>/i', $html, $scripts);
    expect($scripts[0])->not->toBeEmpty();

    $csp = $response->headers->get('Content-Security-Policy');
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
