<?php

use App\Modules\LoadedModule;
use App\Modules\Module;
use App\Modules\ModuleManifest;
use App\Modules\ModuleRegistry;
use App\Modules\ShellModules;
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

// Module assets: one stylesheet per module with a style, and a JSON block the SPA reads to import entries.
function shellModulesBlock(string $html): array
{
    preg_match('#<script type="application/json" id="emailsquire-modules"[^>]*>(.*?)</script>#s', $html, $m);

    return ['raw' => $m[1] ?? null, 'list' => isset($m[1]) ? json_decode($m[1], true) : null];
}

function stubModule(string $name, string $version, ?string $entry, ?string $style): LoadedModule
{
    return new LoadedModule(
        new ModuleManifest($name, $version, '^1.0', 'X\\', 'X\\P', 'autoload.php', 'x_', '/x', $entry, $style, '/tmp'),
        Mockery::mock(Module::class),
    );
}

it('renders an empty module list and no module links when none is loaded', function () {
    $html = $this->get('/')->assertOk()->getContent();

    expect(shellModulesBlock($html)['list'])->toBe([]);
    expect($html)->not->toContain('/modules/');
});

it('links the example module stylesheet and lists its entry', function () {
    config([
        'emailsquire.modules_root' => 'tests/fixtures/modules',
        'emailsquire.modules_require_root_owner' => false,
    ]);
    bootWithModules(fixtureModule('example'));
    app()->usePublicPath(base_path('tests/fixtures/vite'));
    Vite::useBuildDirectory('build')->useManifestFilename('manifest.json');

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->toContain('<link rel="stylesheet" href="/modules/example/entry.css?v=1.0.0">');
    expect(substr_count($html, '/modules/example/entry.css'))->toBe(1);
    expect(shellModulesBlock($html)['list'])->toBe([['name' => 'example', 'entry' => '/modules/example/entry.js?v=1.0.0']]);
});

it('keeps the content security policy unchanged with modules loaded', function () {
    $without = $this->get('/')->headers->get('Content-Security-Policy');
    config([
        'emailsquire.modules_root' => 'tests/fixtures/modules',
        'emailsquire.modules_require_root_owner' => false,
    ]);
    bootWithModules(fixtureModule('example'));
    app()->usePublicPath(base_path('tests/fixtures/vite'));
    Vite::useBuildDirectory('build')->useManifestFilename('manifest.json');

    $with = $this->get('/')->headers->get('Content-Security-Policy');

    $norm = fn (string $csp) => preg_replace('/nonce-[^\']+/', 'nonce-X', $csp);
    expect($norm($with))->toBe($norm($without));
});

it('skips modules without an entry in the list but still links their style', function () {
    $shell = new ShellModules(new ModuleRegistry([
        stubModule('styled', '1.0.0', null, 'a.css'),
        stubModule('scripted', '2.0.0', 'b.js', null),
    ]));

    expect($shell->entries())->toBe([['name' => 'scripted', 'entry' => '/modules/scripted/b.js?v=2.0.0']]);
    expect($shell->styles())->toBe(['/modules/styled/a.css?v=1.0.0']);
});

it('encodes the module block so it cannot close the script element', function () {
    $evil = '</script><b>&\'"';
    $registry = new ModuleRegistry([stubModule('evil', $evil, 'e.js', 'e.css')]);
    $this->app->instance(ModuleRegistry::class, $registry);

    $html = $this->get('/')->assertOk()->getContent();
    $block = shellModulesBlock($html);

    expect($block['raw'])->not->toContain('</script>')->not->toContain('<')->not->toContain('&');
    expect($block['list'])->toBe([['name' => 'evil', 'entry' => '/modules/evil/e.js?v='.rawurlencode($evil)]]);
});

it('keeps a hostile version from breaking out of the stylesheet link', function () {
    $registry = new ModuleRegistry([stubModule('evil', '1"><img src=x>', 'e.js', 'e.css')]);
    $this->app->instance(ModuleRegistry::class, $registry);

    $html = $this->get('/')->assertOk()->getContent();

    expect($html)->not->toContain('<img src=x>');
    expect($html)->toContain('href="/modules/evil/e.css?v=1%22%3E%3Cimg%20src%3Dx%3E"');
});
