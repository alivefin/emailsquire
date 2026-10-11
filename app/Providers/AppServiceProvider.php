<?php

namespace App\Providers;

use App\Auth\AuthFailureLogger;
use App\Auth\ConsoleCredentials;
use App\Auth\DomainAccess;
use App\Auth\GuardSessionHandler;
use App\Auth\LoginResponse;
use App\Auth\LoginThrottle;
use App\Auth\LogoutResponse;
use App\Auth\Mailbox\MailboxPrincipal;
use App\Auth\Mailbox\MailboxUserProvider;
use App\Auth\PrepareAuthenticatedSession;
use App\Auth\RecordSignIn;
use App\Auth\RedirectIfTwoFactorAuthenticatable;
use App\Auth\SignInRouting;
use App\Auth\SingleGuardSession;
use App\Auth\TwoFactorCodeProvider;
use App\Auth\TwoFactorLoginResponse;
use App\Http\Requests\LoginRequest;
use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DovecotAuthClient;
use App\MailServer\Dovecot\FakeDovecotAuthClient;
use App\MailServer\Dovecot\PasswordChecker;
use App\Models\User;
use App\OpenApi\ApiDocumentTransformer;
use Closure;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Laravel\Fortify\Actions\AttemptToAuthenticate;
use Laravel\Fortify\Actions\CanonicalizeUsername;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

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
        // POST /auth/login validates the optional `mailbox` flag too (SignInRouting reads it).
        $this->app->bind(FortifyLoginRequest::class, LoginRequest::class);
        // Mailbox passwords are Dovecot's to check. Only the e2e environment (Playwright, no mail server)
        // checks the stored {BLF-CRYPT} hash itself; the doctor fails it on a real mail root.
        if ($this->app->environment('e2e')) {
            $this->app->singleton(PasswordChecker::class, FakeDovecotAuthClient::class);
        } else {
            $this->app->singleton(PasswordChecker::class, fn (Application $app) => $app->make(DovecotAuthClient::class));
        }
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
        // One memo of the user's grants per request, never across requests: a grant removed between two
        // requests is gone on the second one. PHP-FPM starts every request afresh; a process that serves
        // several (the test suite, a worker) drops scoped instances when each one ends.
        $this->app->scoped(DomainAccess::class);
        $this->app->terminating(fn () => $this->app->forgetScopedInstances());
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
        $this->bootMailboxSignIn();
    }

    private function bootMailboxSignIn(): void
    {
        // The `mailbox` guard's provider (config/auth.php).
        Auth::provider('mailbox', fn (Application $app) => $app->make(MailboxUserProvider::class));

        // The database session driver records the console user and the portal mailbox in their own columns.
        $this->app->make(SessionManager::class)->extend('database', function (Application $app) {
            $connection = config('session.connection');

            return new GuardSessionHandler(
                $app->make('db')->connection(is_string($connection) && $connection !== '' ? $connection : null),
                config()->string('session.table'),
                config()->integer('session.lifetime'),
                $app,
            );
        });

        // Mailbox checks (sign-ins the console account did not take, of an address on a hosted domain:
        // SignInRouting), on top of `login`: five a minute per address and IP, and thirty a minute per IP
        // whatever the address. Counted and cleared by LoginThrottle, not by route middleware.
        RateLimiter::for(LoginThrottle::MAILBOX_LIMITER, fn (Request $request) => [
            Limit::perMinute(5)->by(LoginThrottle::mailboxKey($request->input('email'), $request->ip())),
            Limit::perMinute(30)->by(LoginThrottle::mailboxIpKey($request->ip())),
        ]);

        // Six portal password changes a minute per mailbox (PUT /portal/password), which checks the
        // current password with Dovecot: wrong guesses count, like every other attempt.
        RateLimiter::for('mailbox-password', function (Request $request) {
            $mailbox = $request->user('mailbox');

            return Limit::perMinute(6)->by($mailbox instanceof MailboxPrincipal ? 'mailbox:'.strtolower($mailbox->address) : 'ip:'.$request->ip());
        });

        // The portal's own changes (PUT /portal/forwarding, POST and DELETE /portal/aliases): 30 a
        // minute per mailbox, so one owner can't churn the guide tables.
        RateLimiter::for('portal-writes', function (Request $request) {
            $mailbox = $request->user('mailbox');

            return Limit::perMinute(30)->by($mailbox instanceof MailboxPrincipal ? 'mailbox:'.strtolower($mailbox->address) : 'ip:'.$request->ip());
        });

        Event::listen(Login::class, [SingleGuardSession::class, 'handle']);
    }

    private function bootConsoleSignIn(): void
    {
        // Five attempts a minute per email+ip, counted before the password is checked. This runs before
        // validation, so a non-string email (email[]=…) must not break it; validation then answers 422.
        // A finished sign-in (PrepareAuthenticatedSession, TwoFactorChallengeController) clears it: LoginThrottle.
        RateLimiter::for(LoginThrottle::LIMITER, fn (Request $request) => Limit::perMinute(5)->by(
            LoginThrottle::key($request->input('email'), $request->ip()),
        ));

        // Five two-factor challenges a minute per pending sign-in and IP (POST /auth/two-factor-challenge).
        RateLimiter::for('two-factor', function (Request $request) {
            $pending = $request->session()->get('login.id');

            return Limit::perMinute(5)->by((is_int($pending) || is_string($pending) ? (string) $pending : '').'|'.$request->ip());
        });

        // Six password or code guesses a minute per signed-in user, shared by step-up (POST
        // /auth/confirm-password) and password change (PUT /me/password), which both check the password.
        RateLimiter::for('step-up', function (Request $request) {
            $user = $request->user();

            return Limit::perMinute(6)->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });

        // "Sign out everywhere" (POST /auth/logout-all): 5 an hour per signed-in user. Each one queues a
        // security notice, so this also caps the mail a stolen session can make the user receive.
        RateLimiter::for('logout-all', function (Request $request) {
            $user = $request->user();

            return Limit::perHour(5)->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });

        // Forgotten-password requests (POST /auth/forgot-password): 5 an hour per email and 20 an hour
        // per IP address. Counted before validation, like login, so a non-string email can't break it.
        RateLimiter::for('forgot-password', function (Request $request) {
            $email = $request->input('email');

            return [
                Limit::perHour(5)->by('email:'.Str::transliterate(Str::lower(is_string($email) ? $email : ''))),
                Limit::perHour(20)->by('ip:'.$request->ip()),
            ];
        });

        // Reset attempts (POST /auth/reset-password): 10 a minute per IP address. A token is 64 hex
        // characters, so this is about noise, not guessing; its own bucket keeps setup's apart.
        RateLimiter::for('reset-password', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        // Invitations created or resent (POST …/invitations, …/resend): 30 an hour per signed-in user. Each
        // one queues a mail to an address the user chose, so this caps what one account can send.
        RateLimiter::for('invitations', function (Request $request) {
            $user = $request->user();

            return Limit::perHour(30)->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });

        // Invitation links (POST /auth/invitations/lookup): 30 lookups a minute per IP address, and 10
        // accepts (POST /auth/invitations/accept). A token is 32 random bytes, so this is about noise,
        // not guessing.
        RateLimiter::for('invitation-lookup', fn (Request $request) => Limit::perMinute(30)->by('ip:'.$request->ip()));
        RateLimiter::for('invitation-accept', fn (Request $request) => Limit::perMinute(10)->by('ip:'.$request->ip()));

        // One password hash check per sign-in, whatever exists (ConsoleCredentials, DummyHash).
        Fortify::authenticateUsing(fn (Request $request): ?User => ConsoleCredentials::check($request));

        // No remember-me: a remember cookie would re-authenticate a new session and restart the
        // 12 h clock. Fortify reads `remember` from the request, so drop it before it gets there.
        // SignInRouting lets a sign-in the console account takes go on, and signs anything else in as a
        // mailbox or refuses it (spec decision 1). A user with confirmed two-factor stops at
        // RedirectIfTwoFactorAuthenticatable: it answers {"two_factor": true}, regenerates the session
        // id and keeps `login.id` and `login.at` in the session for /auth/two-factor-challenge.
        Fortify::authenticateThrough(fn () => [
            CanonicalizeUsername::class,
            function (Request $request, Closure $next) {
                $request->merge(['remember' => false]);

                return $next($request);
            },
            SignInRouting::class,
            RedirectIfTwoFactorAuthenticatable::class,
            AttemptToAuthenticate::class,
            PrepareAuthenticatedSession::class,
        ]);

        Event::listen(Failed::class, [AuthFailureLogger::class, 'handle']);
        Event::listen(Login::class, [RecordSignIn::class, 'handle']);
    }
}
