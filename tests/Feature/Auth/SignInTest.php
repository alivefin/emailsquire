<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

function consoleAdmin(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'admin@example.org',
        'password' => 'correct horse battery',
        'is_server_admin' => true,
        'password_set_at' => now(),
        'remember_token' => null,
    ], $attributes));
}

function signIn(string $email = 'admin@example.org', string $password = 'correct horse battery', array $extra = [])
{
    test()->getJson('/api/v1/auth/csrf');

    return test()->postJson('/api/v1/auth/login', ['email' => $email, 'password' => $password] + $extra);
}

it('an admin signs in and gets their profile', function () {
    $user = consoleAdmin();

    signIn()->assertOk()->assertExactJson([
        'id' => $user->id, 'name' => $user->name, 'email' => 'admin@example.org', 'is_server_admin' => true, 'locale' => 'en',
        'two_factor' => ['enabled' => false, 'confirmed' => false, 'recovery_codes_left' => 0], 'two_factor_required' => false,
        'managers_can_share' => false, 'landing' => null,
    ]);

    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('is_server_admin', true);
    expect($user->refresh()->last_login_at)->not->toBeNull();
    expect(session('auth.signed_in_at'))->toBeInt();
});

it('email is matched case-insensitively', function () {
    consoleAdmin();

    signIn('Admin@Example.ORG')->assertOk();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('email', 'admin@example.org');
});

it('a wrong password is 422 validation_failed and is logged for fail2ban', function () {
    consoleAdmin();
    Log::shouldReceive('channel')->with('stack')->andReturnSelf();
    Log::shouldReceive('warning')->once()->with('emailsquire auth failure user=admin@example.org ip=127.0.0.1');

    signIn(password: 'nope nope nope nope')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonPath('errors.email', ['Email or password is wrong.']);
});

it('an unknown email gets the identical response body as a wrong password', function () {
    consoleAdmin();

    $wrong = signIn(password: 'nope nope nope nope');
    $unknown = signIn('nobody@example.org', 'nope nope nope nope');

    $unknown->assertStatus(422);
    expect($unknown->getContent())->toBe($wrong->getContent());
});

it('a user who never set a password cannot sign in', function () {
    consoleAdmin(['password_set_at' => null]);

    signIn()->assertStatus(422)->assertJsonPath('errors.email', ['Email or password is wrong.']);
    $this->getJson('/api/v1/me')->assertStatus(401);
});

it('the sixth attempt in a minute for one email+ip is 429', function () {
    consoleAdmin();

    foreach (range(1, 5) as $_) {
        signIn(password: 'nope nope nope nope')->assertStatus(422);
    }

    $response = signIn(password: 'nope nope nope nope');
    $response->assertStatus(429)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'too_many_requests');
    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

it('sign-in regenerates the session id', function () {
    consoleAdmin();
    $this->getJson('/api/v1/auth/csrf');
    $before = session()->getId();

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'correct horse battery'])->assertOk();

    expect(session()->getId())->not->toBe($before);
});

it('logout invalidates the session', function () {
    consoleAdmin();
    signIn()->assertOk();

    $this->postJson('/api/v1/auth/logout')->assertNoContent();
    $this->getJson('/api/v1/me')->assertStatus(401)->assertJsonPath('code', 'unauthenticated');
});

it('a non-admin console user can sign in but /me shows is_server_admin false', function () {
    consoleAdmin(['is_server_admin' => false]);

    signIn()->assertOk()->assertJsonPath('is_server_admin', false);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('is_server_admin', false);
});

it('refuses a cross-origin login attempt', function () {
    consoleAdmin();

    $this->withHeader('Origin', 'https://evil.example')
        ->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'correct horse battery'])
        ->assertStatus(403)
        ->assertJsonPath('code', 'cross_origin');
});

it('never issues a remember-me cookie or token', function () {
    $user = consoleAdmin();

    $response = signIn(extra: ['remember' => true])->assertOk();

    $names = collect($response->headers->getCookies())->map->getName()->all();
    foreach ($names as $name) {
        expect($name)->not->toStartWith('remember_web_');
    }
    expect($user->refresh()->remember_token)->toBeNull();
});

it('the unknown-email path does one hash check and no hash make, like a known user', function () {
    consoleAdmin();
    signIn('nobody@example.org', 'nope nope nope nope')->assertStatus(422); // builds the dummy hash

    Hash::spy();
    signIn('nobody@example.org', 'nope nope nope nope')->assertStatus(422);

    Hash::shouldHaveReceived('check')->once();
    Hash::shouldNotHaveReceived('make');
});

it('registers exactly the auth routes the console needs', function () {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($r) => str_starts_with($r->uri(), 'api/v1/auth/'))
        ->flatMap(fn ($r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')->map(fn ($m) => $m.' '.$r->uri()))
        ->sort()->values()->all();

    expect($routes)->toBe([
        'GET api/v1/auth/csrf',
        'POST api/v1/auth/confirm-password',
        'POST api/v1/auth/forgot-password',
        'POST api/v1/auth/login',
        'POST api/v1/auth/logout',
        'POST api/v1/auth/logout-all',
        'POST api/v1/auth/reset-password',
        'POST api/v1/auth/setup',
        'POST api/v1/auth/two-factor-challenge',
    ]);
});

it('a non-string email or password is a 422 problem, not a server error', function (array $body) {
    consoleAdmin();

    $this->postJson('/api/v1/auth/login', $body)->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'validation_failed');
})->with([
    'email array' => [['email' => ['admin@example.org'], 'password' => 'correct horse battery']],
    'password array' => [['email' => 'admin@example.org', 'password' => ['correct horse battery']]],
]);

it('signing in again while signed in answers with JSON, never a redirect', function () {
    $user = consoleAdmin();
    signIn()->assertOk();

    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'correct horse battery'])
        ->assertOk()->assertJsonPath('id', $user->id);
    $this->postJson('/api/v1/auth/login', ['email' => 'admin@example.org', 'password' => 'nope nope nope nope'])
        ->assertStatus(422)->assertJsonPath('code', 'validation_failed');
});
