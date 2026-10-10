<?php

namespace App\Providers;

use App\Auth\AuthFailureLogger;
use App\Auth\LoginResponse;
use App\Auth\LogoutResponse;
use App\Auth\RecordSignIn;
use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\Models\User;
use Closure;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Actions\PrepareAuthenticatedSession;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DoveadmClient::class, fn () => new DoveadmClient(
            config()->string('emailsquire.doveadm.url'),
            $this->apiKey(),
            config()->integer('emailsquire.doveadm.timeout'),
        ));
        $this->app->singleton(DovecotAuthClient::class, fn () => new DovecotAuthClient(
            config()->string('emailsquire.dovecot_auth.socket'),
            config()->integer('emailsquire.dovecot_auth.timeout'),
        ));
        $this->app->singleton(LoginResponseContract::class, LoginResponse::class);
        $this->app->singleton(LogoutResponseContract::class, LogoutResponse::class);
    }

    private function apiKey(): ?string
    {
        $key = config('emailsquire.doveadm.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        config(['auth.passwords.admin_setup.expire' => config('emailsquire.admin_setup_expire_minutes')]);

        $this->bootConsoleSignIn();
    }

    private function bootConsoleSignIn(): void
    {
        // Five attempts a minute per email+ip, counted before the password is checked.
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(5)->by(
            Str::transliterate(Str::lower($request->string('email')->toString()).'|'.$request->ip()),
        ));

        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', Str::lower($request->string('email')->toString()))->first();
            $password = $request->string('password')->toString();

            if ($user === null || $user->password_set_at === null) {
                // Same work as a real check, so timing doesn't tell the two cases apart.
                Hash::check($password, Hash::make('timing-equalizer'));

                return null;
            }

            return Hash::check($password, $user->password) ? $user : null;
        });

        // No remember-me: a remember cookie would re-authenticate a new session and restart the
        // 12 h clock. Fortify reads `remember` from the request, so drop it before it gets there.
        Fortify::authenticateThrough(fn () => [
            CanonicalizeUsername::class,
            function (Request $request, Closure $next) {
                $request->merge(['remember' => false]);

                return $next($request);
            },
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        Event::listen(Failed::class, [AuthFailureLogger::class, 'handle']);
        Event::listen(Login::class, [RecordSignIn::class, 'handle']);
    }
}
