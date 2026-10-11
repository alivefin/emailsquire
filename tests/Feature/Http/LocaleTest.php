<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

function localeAdmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'admin@example.org',
        'password' => 'correct horse battery',
        'is_server_admin' => true,
        'password_set_at' => now(),
        'remember_token' => null,
    ], $attributes));
}

function wrongLogin(string $acceptLanguage = ''): TestResponse
{
    test()->getJson('/api/v1/auth/csrf');
    $headers = $acceptLanguage === '' ? [] : ['Accept-Language' => $acceptLanguage];

    return test()->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'nope nope nope nope'], $headers);
}

it('a guest sending Accept-Language fi gets the Finnish login failure', function () {
    localeAdmin();

    wrongLogin('fi')->assertStatus(422)->assertJsonPath('errors.email', ['Sähköpostiosoite tai salasana on väärin.']);
});

it('uses the first language tag and matches fi only as a whole subtag', function (?string $header, string $expected) {
    localeAdmin();
    $messages = ['en' => 'Email or password is wrong.', 'fi' => 'Sähköpostiosoite tai salasana on väärin.'];

    wrongLogin($header ?? '')->assertJsonPath('errors.email', [$messages[$expected]]);
})->with([
    'fi' => ['fi', 'fi'],
    'FI' => ['FI', 'fi'],
    'fi-FI with weights' => ['fi-FI,en;q=0.8', 'fi'],
    'fi_FI' => ['fi_FI', 'fi'],
    'fil-PH' => ['fil-PH', 'en'],
    'sv-FI' => ['sv-FI', 'en'],
    'sv' => ['sv', 'en'],
    'en first, fi second' => ['en,fi;q=0.5', 'en'],
    'missing' => [null, 'en'],
]);

it('a signed-in user with locale fi keeps Finnish whatever Accept-Language says', function () {
    Route::middleware(['web', 'auth'])->get('/api/v1/_locale', fn () => response()->json(['locale' => app()->getLocale()]));
    $this->actingAs(localeAdmin(['locale' => 'fi']));

    $this->getJson('/api/v1/_locale', ['Accept-Language' => 'en'])->assertOk()->assertJsonPath('locale', 'fi');
});

it('a signed-in user with locale en wins over a Finnish header', function () {
    Route::middleware(['web', 'auth'])->get('/api/v1/_locale', fn () => response()->json(['locale' => app()->getLocale()]));
    $this->actingAs(localeAdmin(['locale' => 'en']));

    $this->getJson('/api/v1/_locale', ['Accept-Language' => 'fi'])->assertOk()->assertJsonPath('locale', 'en');
});

it('PATCH /me persists the locale and returns the me payload', function () {
    $user = localeAdmin();
    $this->actingAs($user);

    $this->patchJson('/api/v1/me', ['locale' => 'fi'])
        ->assertOk()
        ->assertExactJson([
            'id' => $user->id, 'name' => $user->name, 'email' => 'admin@example.org',
            'is_server_admin' => true, 'locale' => 'fi',
            'two_factor' => ['enabled' => false, 'confirmed' => false, 'recovery_codes_left' => 0], 'two_factor_required' => false,
            'managers_can_share' => false, 'landing' => null,
            'capabilities' => ['schema_extension' => ['receiving' => false, 'sendonly' => false], 'catch_all' => false],
        ]);
    expect($user->refresh()->locale)->toBe('fi');
});

it('PATCH /me rejects an unsupported or missing locale with 422', function (array $body) {
    $user = localeAdmin();
    $this->actingAs($user);

    $this->patchJson('/api/v1/me', $body)
        ->assertStatus(422)
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['locale']]);
    expect($user->refresh()->locale)->toBe('en');
})->with([
    'de' => [['locale' => 'de']],
    'fi-FI' => [['locale' => 'fi-FI']],
    'missing' => [[]],
]);

it('PATCH /me needs a session', function () {
    $this->patchJson('/api/v1/me', ['locale' => 'fi'])->assertStatus(401);
});

it('GET /me includes the locale', function () {
    $this->actingAs(localeAdmin(['locale' => 'fi']));

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('locale', 'fi');
});

it('answers a 500 problem in the header language when the database is down mid-request', function () {
    $user = localeAdmin();
    $original = config('database.connections.mysql');
    // A session that names a user, so Authenticate and the renderer both ask the database for them.
    $this->withSession([auth()->guard('web')->getName() => $user->id]);

    try {
        config(['database.connections.mysql.database' => 'emailsquire_no_such_database']);
        DB::purge('mysql');

        $this->getJson('/api/v1/me', ['Accept-Language' => 'fi'])
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertJsonPath('code', 'server_error')
            ->assertJsonPath('detail', 'Jokin meni pieleen.');
    } finally {
        config(['database.connections.mysql' => $original]);
        DB::purge('mysql');
    }
});

it('marks language-dependent responses with Vary: Accept-Language', function (string $method, string $uri, int $status) {
    localeAdmin();
    $this->getJson('/api/v1/auth/csrf');

    $response = $method === 'post'
        ? $this->postJson($uri, ['email' => 'admin@example.org', 'password' => 'nope nope nope nope'])
        : $this->getJson($uri);

    $response->assertStatus($status);
    $vary = array_map('trim', explode(',', (string) $response->headers->get('Vary')));
    expect($vary)->toContain('Accept-Language')
        ->and(array_count_values($vary)['Accept-Language'])->toBe(1);
})->with([
    'a web-group answer' => ['get', '/api/v1/auth/csrf', 204],
    'a validation problem from the web group' => ['post', '/api/v1/auth/login', 422],
    'a problem rendered outside the web group' => ['get', '/api/v1/nope', 404],
    'a guest 401 problem' => ['get', '/api/v1/me', 401],
]);

it('marks the cross-origin refusal with Vary: Accept-Language once', function () {
    $response = $this->postJson('/api/v1/auth/login', [], ['Sec-Fetch-Site' => 'cross-site'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'cross_origin');

    $vary = array_map('trim', explode(',', (string) $response->headers->get('Vary')));
    expect(array_count_values($vary)['Accept-Language'] ?? 0)->toBe(1);
});
