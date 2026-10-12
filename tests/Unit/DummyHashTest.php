<?php

use App\Auth\DummyHash;
use Illuminate\Support\Facades\Hash;

it('the first dummy verification never creates a hash and matches the configured work factors', function (string $driver, array $options) {
    config(['hashing.driver' => $driver, 'hashing.'.($driver === 'bcrypt' ? 'bcrypt' : 'argon') => $options]);
    app('hash')->forgetDrivers();
    $hasher = Hash::driver();
    $real = $hasher->make('a real account password');
    // Reproduce the old per-request cold state even when another test used DummyHash already.
    if (property_exists(DummyHash::class, 'hash')) {
        (new ReflectionProperty(DummyHash::class, 'hash'))->setValue(null, null);
    }
    $makes = 0;
    Hash::partialMock()->shouldReceive('make')->andReturnUsing(function (string $value) use ($hasher, &$makes) {
        $makes++;

        return $hasher->make($value);
    });
    Hash::shouldReceive('check')->once()->withArgs(function (string $value, string $dummy) use ($hasher, $real) {
        expect($hasher->info($dummy))->toBe($hasher->info($real))
            ->and($hasher->needsRehash($dummy))->toBeFalse()
            ->and($hasher->check($value, $dummy))->toBeFalse();

        return true;
    })->andReturn(false);

    DummyHash::check('wrong account password');
    expect($makes)->toBe(0);
})->with([
    'test bcrypt' => ['bcrypt', ['rounds' => 4, 'verify' => true]],
    'production bcrypt' => ['bcrypt', ['rounds' => 12, 'verify' => true]],
    'environment bcrypt' => ['bcrypt', ['rounds' => '4', 'verify' => true]],
    'argon default' => ['argon', ['memory' => 65536, 'time' => 4, 'threads' => 1, 'verify' => true]],
    'argon custom' => ['argon', ['memory' => 8192, 'time' => 2, 'threads' => 2, 'verify' => true]],
    'argon2id install guide' => ['argon2id', ['memory' => 65536, 'time' => 3, 'threads' => 1, 'verify' => true]],
    'argon2id custom' => ['argon2id', ['memory' => 8192, 'time' => 2, 'threads' => 2, 'verify' => true]],
    'environment argon2id' => ['argon2id', ['memory' => '8192', 'time' => '2', 'threads' => '1', 'verify' => true]],
]);
