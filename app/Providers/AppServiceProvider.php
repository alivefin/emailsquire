<?php

namespace App\Providers;

use App\Auth\AuthFailureLogger;
use App\Auth\LoginResponse;
use App\Auth\LogoutResponse;
use App\Auth\RecordSignIn;
use App\Auth\RedirectIfTwoFactorAuthenticatable;
use App\Auth\TwoFactorCodeProvider;
use App\Auth\TwoFactorLoginResponse;
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
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;

class AppServiceProvider extends ServiceProvider
{
    private static ?string $dummyHash = null;

    /** Request attribute holding the user whose password this request already checked. */
    private const CHECKED_USER = 'emailsquire.password_checked_user';

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
        $this->app->singleton(TwoFactorLoginResponseContract::class, TwoFactorLoginResponse::class);
        // Replaces Fortify's provider: used codes are marked per secret with an atomic add (replay).
        $this->app->singleton(TwoFactorAuthenticationProvider::class, TwoFactorCodeProvider::class);
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
        config(['mail.from.address' => config('emailsquire.mail_from')]);
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

        // Five two-factor challenges a minute per pending sign-in and IP (POST /auth/two-factor-challenge).
        RateLimiter::for('two-factor', function (Request $request) {
            $pending = $request->session()->get('login.id');

            return Limit::perMinute(5)->by((is_int($pending) || is_string($pending) ? (string) $pending : '').'|'.$request->ip());
        });

        // Six step-up confirmations a minute (POST /auth/confirm-password), per signed-in user.
        RateLimiter::for('step-up', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(6)->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });

        // Built on first use with the configured driver and cost, so the unknown-email path costs
        // exactly one Hash::check, like the known-user path. The pipeline calls this twice for a user
        // without two-factor (RedirectIfTwoFactorAuthenticatable, then AttemptToAuthenticate); the
        // second call reuses the first one's result instead of hashing again.
        Fortify::authenticateUsing(function (Request $request): ?User {
            $checked = $request->attributes->get(self::CHECKED_USER);
            if ($checked instanceof User) {
                return $checked;
            }

            $user = User::where('email', Str::lower($request->string('email')->toString()))->first();
            $password = $request->string('password')->toString();

            if ($user === null || $user->password_set_at === null) {
                self::$dummyHash ??= Hash::make(Str::random(32));
                Hash::check($password, self::$dummyHash);

                return null;
            }

            if (! Hash::check($password, $user->password)) {
                return null;
            }
            $request->attributes->set(self::CHECKED_USER, $user);

            return $user;
        });

        // No remember-me: a remember cookie would re-authenticate a new session and restart the
        // 12 h clock. Fortify reads `remember` from the request, so drop it before it gets there.
        // A user with confirmed two-factor stops at RedirectIfTwoFactorAuthenticatable: it answers
        // {"two_factor": true}, regenerates the session id and keeps `login.id` and `login.at` in the
        // session for /auth/two-factor-challenge.
        Fortify::authenticateThrough(fn () => [
            CanonicalizeUsername::class,
            function (Request $request, Closure $next) {
                $request->merge(['remember' => false]);

                return $next($request);
            },
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        Event::listen(Failed::class, [AuthFailureLogger::class, 'handle']);
        Event::listen(Login::class, [RecordSignIn::class, 'handle']);
    }
}
