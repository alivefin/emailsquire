<?php

use App\MailServer\Exceptions\ClaimRefused;
use App\MailServer\Exceptions\Conflict;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Route;

final class ThrowsHttpResponse
{
    public function handle(): never
    {
        throw new HttpResponseException(response()->json(['ok' => true], 202));
    }
}

beforeEach(function () {
    Route::middleware('web')->prefix('api/v1')->group(function () {
        Route::post('_t/validate', fn () => request()->validate(['name' => 'required']));
        Route::get('_t/auth', fn () => 'ok')->middleware('auth');
        Route::get('_t/model', fn () => User::query()->findOrFail(0));
        Route::get('_t/conflict', fn () => throw new Conflict('domain_exists', 'That domain already exists.'));
        Route::get('_t/claim', fn () => throw new ClaimRefused('domain_claimed', 'Claimed elsewhere.', 'name'));
        Route::get('_t/throttle', fn () => throw new ThrottleRequestsException('slow', null, ['Retry-After' => '30']));
        Route::get('_t/response', fn () => 'no')->middleware(ThrowsHttpResponse::class);
        Route::get('_t/boom', fn () => throw new RuntimeException('secret detail'));
    });
});

it('renders a validation error as problem+json with errors', function () {
    $this->postJson('/api/v1/_t/validate', [])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('title', 'The given data was invalid.')
        ->assertJsonPath('type', 'about:blank')
        ->assertJsonPath('code', 'validation_failed')
        ->assertJsonStructure(['errors' => ['name']]);
});

it('renders an unauthenticated request as 401 problem+json', function () {
    $this->get('/api/v1/_t/auth')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'unauthenticated');
});

it('renders a missing model as 404 problem+json without internals', function () {
    $response = $this->getJson('/api/v1/_t/model')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'not_found');

    expect($response->getContent())->not->toContain('ModelNotFound')->not->toContain('.php');
});

it('renders an unknown api path as 404 problem+json', function () {
    $this->get('/api/v1/nope')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'not_found');
});

it('renders a Conflict as 409 with its code', function () {
    $this->getJson('/api/v1/_t/conflict')
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'domain_exists')
        ->assertJsonPath('detail', 'That domain already exists.');
});

it('renders a ClaimRefused as 422 with the field error', function () {
    $this->getJson('/api/v1/_t/claim')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'domain_claimed')
        ->assertJsonPath('errors.name', ['Claimed elsewhere.']);
});

it('renders a throttled request as 429 with Retry-After', function () {
    $this->getJson('/api/v1/_t/throttle')
        ->assertStatus(429)
        ->assertHeader('Retry-After', '30')
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('code', 'too_many_requests');
});

it('renders an unexpected exception as 500 without leaking the message', function () {
    config(['app.debug' => false]);

    $response = $this->getJson('/api/v1/_t/boom')
        ->assertStatus(500)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('detail', 'Something went wrong.');

    expect($response->getContent())->not->toContain('secret detail')->not->toContain('RuntimeException');
});

it('serves the csrf endpoint with an XSRF-TOKEN cookie', function () {
    $this->get('/api/v1/auth/csrf')->assertNoContent()->assertCookie('XSRF-TOKEN');
});

it('passes an HttpResponseException response through untouched', function () {
    $this->getJson('/api/v1/_t/response')->assertStatus(202)->assertExactJson(['ok' => true]);
});
