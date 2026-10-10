<?php

use App\Http\Locale;
use App\Http\Middleware\RefuseDebugInProduction;
use App\Http\Middleware\RequireStepUp;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ValidateCsrfToken;
use App\Http\Problem;
use App\MailServer\Exceptions\ClaimRefused;
use App\MailServer\Exceptions\Conflict;
use App\MailServer\Exceptions\DomainLimitExceeded;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Security headers sit outermost so refusals and error pages carry them too.
        $middleware->prepend([SecurityHeaders::class, RefuseDebugInProduction::class]);
        $middleware->web(replace: [PreventRequestForgery::class => ValidateCsrfToken::class]);
        // After the session starts, so the signed-in user's locale is known.
        $middleware->web(append: [SetLocale::class]);
        // There is no login route; guests get a 401 problem from the exception handler.
        $middleware->redirectGuestsTo(fn () => null);
        // A password change (a setup link issued with create-admin --reset) ends every other session
        // of that user: their stored password hash no longer matches, so they get a 401 problem.
        $middleware->authenticateSessions();
        // Routes that need a fresh password or code (POST /auth/confirm-password) answer 423 without one.
        $middleware->alias(['step-up' => RequireStepUp::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontReport([Conflict::class, ClaimRefused::class, DomainLimitExceeded::class]);

        // Laravel has already turned model-not-found, authorization and token-mismatch
        // exceptions into HttpExceptions by the time this runs.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // Some errors (419, 403 cross-origin) are thrown before SetLocale runs.
            Locale::apply($request);

            $response = match (true) {
                // Fortify's own lockout is a ValidationException with status 429.
                $e instanceof ValidationException && $e->status === 429 => Problem::response(
                    429, 'Too Many Requests', __('emailsquire.problem.too_many_requests'), 'too_many_requests',
                ),
                $e instanceof ValidationException => Problem::response(
                    $e->status,
                    'The given data was invalid.',
                    null,
                    'validation_failed',
                    ['errors' => $e->errors()],
                ),
                $e instanceof AuthenticationException => Problem::response(401, 'Unauthorized', __('emailsquire.problem.unauthenticated'), 'unauthenticated'),
                $e instanceof Conflict => Problem::response(409, 'Conflict', $e->getMessage(), $e->errorCode),
                $e instanceof ClaimRefused => Problem::response(
                    422,
                    'The given data was invalid.',
                    $e->getMessage(),
                    $e->errorCode,
                    ['errors' => [$e->field => [$e->getMessage()]]],
                ),
                $e instanceof DomainLimitExceeded => Problem::response(
                    422,
                    'Domain limit reached',
                    $e->detail(),
                    'domain_limit',
                    ['errors' => [$e->field() => [$e->detail()]]],
                ),
                $e instanceof HttpResponseException => $e->getResponse(),
                $e instanceof HttpExceptionInterface => Problem::fromHttpException($e),
                default => Problem::response(500, 'Server Error', __('emailsquire.problem.server_error'), 'server_error'),
            };

            return $e instanceof HttpResponseException ? $response : Locale::vary($response);
        });
    })->create();
