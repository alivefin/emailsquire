<?php

use App\Auth\AdminSetup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Tests\Support\GuideFixture;

it('setup and reset failures verify once without making a hash', function (string $flow, string $state) {
    GuideFixture::load('trixie');
    $email = 'token-timing@example.org';
    $token = str_repeat('a', 64);
    if ($state !== 'unknown') {
        $user = User::factory()->create(['email' => $email, 'password_set_at' => now(), 'status' => $state === 'deactivated' ? 'deactivated' : 'active']);
        if ($state !== 'missing') {
            $token = $flow === 'setup' ? app(AdminSetup::class)->issue($user) : Password::broker('users')->createToken($user);
            if ($state === 'expired') {
                DB::table($flow === 'setup' ? 'admin_setup_tokens' : 'password_reset_tokens')
                    ->where('email', $email)->update(['created_at' => now()->subDays(4)]);
            } elseif ($state === 'wrong') {
                $token = str_repeat('a', 64);
            }
        }
    }
    // Recreate brokers after wrapping their hasher so real token checks are counted as well.
    app()->forgetInstance('auth.password');
    Password::clearResolvedInstance('auth.password');
    $hasher = Hash::driver();
    $checks = 0;
    $makes = 0;
    Hash::partialMock()->shouldReceive('check')->andReturnUsing(function (string $value, string $hash) use ($hasher, &$checks) {
        $checks++;

        return $hasher->check($value, $hash);
    });
    Hash::shouldReceive('make')->andReturnUsing(function (string $value) use ($hasher, &$makes) {
        $makes++;

        return $hasher->make($value);
    });

    $this->postJson('/api/v1/auth/'.($flow === 'setup' ? 'setup' : 'reset-password'), [
        'email' => $email, 'token' => $token, 'password' => 'a long replacement password 47',
        'password_confirmation' => 'a long replacement password 47',
    ])->assertUnprocessable();

    expect($checks)->toBe(1)->and($makes)->toBe(0);
})->with(['setup', 'reset'])->with(['unknown', 'missing', 'expired', 'wrong', 'deactivated']);
