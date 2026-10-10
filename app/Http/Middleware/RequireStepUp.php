<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a dangerous request through only within 300 s of a POST /auth/confirm-password; otherwise
 * answers 423 `step_up_required` so the SPA can ask for the password or a code and retry. Runs after
 * the route's `can:` middleware, so an out-of-scope resource stays a 404.
 *
 * A route that needs step-up for some of its fields only (PATCH /users/{user}) calls ensure() itself.
 */
class RequireStepUp
{
    /** Laravel's own key, as used by its `password.confirm` middleware. */
    public const SESSION_KEY = 'auth.password_confirmed_at';

    public const MAX_AGE_SECONDS = 300;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        return self::confirmed($request) ? $next($request) : self::problem();
    }

    /** Whether the session confirmed the password or a code within the last 300 seconds. */
    public static function confirmed(Request $request): bool
    {
        $confirmedAt = $request->session()->get(self::SESSION_KEY);

        return is_int($confirmedAt) && now()->getTimestamp() - $confirmedAt <= self::MAX_AGE_SECONDS;
    }

    /**
     * The middleware's check, from inside a controller: throws the same 423 problem unless confirmed.
     *
     * @throws HttpResponseException
     */
    public static function ensure(Request $request): void
    {
        if (! self::confirmed($request)) {
            throw new HttpResponseException(self::problem());
        }
    }

    public static function problem(): JsonResponse
    {
        return Problem::response(423, 'Locked', trans()->string('emailsquire.problem.step_up_required'), 'step_up_required');
    }
}
