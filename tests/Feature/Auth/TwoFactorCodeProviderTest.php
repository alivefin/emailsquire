<?php

use App\Auth\TwoFactorCodeProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use PragmaRX\Google2FA\Google2FA;

function codeProvider(?Repository $cache = null, ?Google2FA $engine = null): TwoFactorCodeProvider
{
    return new TwoFactorCodeProvider($engine ?? app(Google2FA::class), $cache ?? Cache::store());
}

it('is the provider Fortify and the app verify codes with', function () {
    expect(app(TwoFactorAuthenticationProvider::class))->toBeInstanceOf(TwoFactorCodeProvider::class)
        ->and(config('fortify-options.two-factor-authentication.window'))->toBe(1);
});

it('accepts the current code once and refuses it the second time', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $code = app(Google2FA::class)->getCurrentOtp($secret);
    $provider = codeProvider();

    expect($provider->verify($secret, $code))->toBeTrue()
        ->and($provider->verify($secret, $code))->toBeFalse()
        ->and(app(TwoFactorAuthenticationProvider::class)->verify($secret, $code))->toBeFalse();
});

it('accepts a code from the previous 30-second period, once', function () {
    $engine = app(Google2FA::class);
    $secret = $engine->generateSecretKey();
    $previous = $engine->oathTotp($secret, $engine->getTimestamp() - 1);

    expect(codeProvider()->verify($secret, $previous))->toBeTrue()
        ->and(codeProvider()->verify($secret, $previous))->toBeFalse();
});

it('refuses a code three periods old and a wrong code', function () {
    $engine = app(Google2FA::class);
    $secret = $engine->generateSecretKey();
    $current = $engine->getCurrentOtp($secret);
    $old = $engine->oathTotp($secret, $engine->getTimestamp() - 3);

    expect(codeProvider()->verify($secret, $old))->toBe($old === $current)
        ->and(codeProvider()->verify($secret, 'abcdef'))->toBeFalse();
});

it('keys used codes per secret, so the same code value for another user still works', function () {
    // An engine that accepts 123456 for any secret in period 1000, to get the same code for two users.
    $engine = Mockery::mock(Google2FA::class);
    $engine->shouldReceive('verifyKeyNewer')->andReturn(1000);
    $provider = codeProvider(engine: $engine);

    expect($provider->verify('AAAAAAAAAAAAAAAA', '123456'))->toBeTrue()
        ->and($provider->verify('AAAAAAAAAAAAAAAA', '123456'))->toBeFalse()
        ->and($provider->verify('BBBBBBBBBBBBBBBB', '123456'))->toBeTrue();
});

it('marks a used code with one atomic add for 150 seconds, so of two parallel uses only one passes', function () {
    $secret = app(Google2FA::class)->generateSecretKey();
    $code = app(Google2FA::class)->getCurrentOtp($secret);
    // Two requests that both passed the TOTP check race on the same cache key: the store lets one add win.
    $cache = Mockery::mock(Repository::class);
    $keys = [];
    $cache->shouldReceive('add')->twice()
        ->withArgs(function (string $key, $value, int $ttl) use (&$keys, $secret, $code) {
            $keys[] = $key;

            return $ttl === 150 && ! str_contains($key, $secret) && ! str_contains($key, $code);
        })
        ->andReturn(true, false);
    $cache->shouldNotReceive('get', 'put', 'has', 'set', 'forever');
    $provider = codeProvider($cache);

    expect($provider->verify($secret, $code))->toBeTrue()
        ->and($provider->verify($secret, $code))->toBeFalse()
        ->and($keys[0])->toBe($keys[1])->toStartWith('2fa_used:');
});

it('keeps a used code marked for (2 × window + 1) periods plus a minute: 150 s for window 1', function () {
    config(['fortify-options.two-factor-authentication.window' => 1]);
    expect(TwoFactorCodeProvider::usedTtlSeconds())->toBe(150);

    config(['fortify-options.two-factor-authentication.window' => 2]);
    expect(TwoFactorCodeProvider::usedTtlSeconds())->toBe(210);
});
