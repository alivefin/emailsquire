<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

function duUser(array $attributes = []): User
{
    return User::factory()->create(array_merge([
        'email' => 'dana@example.org',
        'password' => 'correct horse battery',
        'is_server_admin' => true,
        'password_set_at' => now(),
    ], $attributes));
}

function duDeactivate(User $user): void
{
    DB::table('users')->where('id', $user->id)->update(['status' => 'deactivated']);
}

/** Only the session cookie says who the request is (see cpAs() in ChangePasswordTest). */
function duAs(string $sessionId)
{
    app('auth')->forgetGuards();
    session()->flush();

    return test()->withCredentials()->withCookie(config('session.cookie'), $sessionId);
}

function duLogin(string $password = 'correct horse battery')
{
    return duAs('')->postJson('/api/v1/auth/login', ['email' => 'dana@example.org', 'password' => $password]);
}

beforeEach(function () {
    config(['emailsquire.require_two_factor' => 'off']);
    Http::fake();
});

it('a deactivated user signing in gets the same 422 as a wrong password', function () {
    $user = duUser();
    $wrong = duLogin('nope nope nope nope')->assertStatus(422);
    duDeactivate($user);

    $deactivated = duLogin()->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect($deactivated->getContent())->toBe($wrong->getContent());
    duAs('')->getJson('/api/v1/me')->assertUnauthorized();
});

it('the deactivated path hashes once with the dummy hash, like an unknown email', function () {
    $user = duUser();
    duDeactivate($user);
    duLogin()->assertStatus(422); // builds the dummy hash

    Hash::spy();
    duLogin()->assertStatus(422);

    Hash::shouldHaveReceived('check')->once()->withArgs(fn (string $value, ?string $hash) => $value === 'correct horse battery' && $hash !== $user->password);
    Hash::shouldNotHaveReceived('make');
});

it('a pending two-factor sign-in of a user deactivated meanwhile is refused as expired', function () {
    $user = duUser();
    $secret = tfaEnrol($user);
    $this->getJson('/api/v1/auth/csrf');
    $this->postJson('/api/v1/auth/login', ['email' => 'dana@example.org', 'password' => 'correct horse battery'])
        ->assertOk()->assertExactJson(['two_factor' => true]);

    duDeactivate($user);

    $this->postJson('/api/v1/auth/two-factor-challenge', ['code' => tfaOtp($secret)])
        ->assertStatus(422)
        ->assertJsonPath('errors.code', ['Your sign-in has expired. Enter your email and password again.']);
    $this->getJson('/api/v1/me')->assertUnauthorized();
    expect(session()->has('login.id'))->toBeFalse();
});

it('a session of a user deactivated in the database answers 401 and is invalidated', function () {
    config(['session.driver' => 'database']);
    $user = duUser();
    $session = duLogin()->assertOk()->getCookie(config('session.cookie'))->getValue();
    duAs($session)->getJson('/api/v1/me')->assertOk();

    duDeactivate($user);

    duAs($session)->getJson('/api/v1/me')->assertUnauthorized()
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated');
    expect(DB::table('sessions')->where('id', $session)->exists())->toBeFalse();

    // Reactivated, the old session still does not come back.
    DB::table('users')->where('id', $user->id)->update(['status' => 'active']);
    duAs($session)->getJson('/api/v1/me')->assertUnauthorized();
});

it('a deactivated user is refused on domain routes too, not only on me', function () {
    $user = duUser();
    $this->actingAs($user);
    $user->forceFill(['status' => 'deactivated']);

    $this->getJson('/api/v1/domains')->assertUnauthorized()->assertJsonPath('code', 'unauthenticated');
});
