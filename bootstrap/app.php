<?php

use App\Http\Middleware\RefuseDebugInProduction;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\ValidateCsrfToken;
use App\Http\Problem;
use App\MailServer\Exceptions\ClaimRefused;
use App\MailServer\Exceptions\Conflict;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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
        // There is no login route; guests get a 401 problem from the exception handler.
        $middleware->redirectGuestsTo(fn () => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->dontReport([Conflict::class, ClaimRefused::class]);

        // Laravel has already turned model-not-found, authorization and token-mismatch
        // exceptions into HttpExceptions by the time this runs.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return match (true) {
                $e instanceof ValidationException => Problem::response(
                    $e->status,
                    'The given data was invalid.',
                    null,
                    'validation_failed',
                    ['errors' => $e->errors()],
                ),
                $e instanceof AuthenticationException => Problem::response(401, 'Unauthorized', 'Authentication is required.', 'unauthenticated'),
                $e instanceof Conflict => Problem::response(409, 'Conflict', $e->getMessage(), $e->errorCode),
                $e instanceof ClaimRefused => Problem::response(
                    422,
                    'The given data was invalid.',
                    $e->getMessage(),
                    $e->errorCode,
                    ['errors' => [$e->field => [$e->getMessage()]]],
                ),
                $e instanceof HttpExceptionInterface => Problem::fromHttpException($e),
                default => Problem::response(500, 'Server Error', 'Something went wrong.', 'server_error'),
            };
        });
    })->create();
