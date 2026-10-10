<?php

namespace App\Providers;

use App\Auth\AuthFailureLogger;
use App\Auth\LoginResponse;
use App\Auth\LogoutResponse;
use App\Auth\RecordSignIn;
use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\Models\User;
use App\OpenApi\ApiDocumentTransformer;
use Closure;
use Dedoc\Scramble\Scramble;
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
    private static ?string $dummyHash = null;

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
        // Only login and logout are wanted (routes/api.php); Fortify would also add confirm-password.
        Fortify::ignoreRoutes();
        Scramble::configure()->withDocumentTransformers(ApiDocumentTransformer::class);
        // The interactive API docs exist only in local and testing; the export command needs no route.
        if (! $this->app->environment(['local', 'testing'])) {
            Scramble::ignoreDefaultRoutes();
        }
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
        // Five attempts a minute per email+ip, counted before the password is checked. This runs before
        // validation, so a non-string email (email[]=…) must not break it; validation then answers 422.
        RateLimiter::for('login', function (Request $request) {
            $email = $request->input('email');

            return Limit::perMinute(5)->by(
                Str::transliterate(Str::lower(is_string($email) ? $email : '').'|'.$request->ip()),
            );
        });

        // Built on first use with the configured driver and cost, so the unknown-email path costs
        // exactly one Hash::check, like the known-user path.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::where('email', Str::lower($request->string('email')->toString()))->first();
            $password = $request->string('password')->toString();

            if ($user === null || $user->password_set_at === null) {
                self::$dummyHash ??= Hash::make(Str::random(32));
                Hash::check($password, self::$dummyHash);

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
