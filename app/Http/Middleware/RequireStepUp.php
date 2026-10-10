<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets a dangerous request through only within 300 s of a POST /auth/confirm-password; otherwise
 * answers 423 `step_up_required` so the SPA can ask for the password or a code and retry. Runs after
 * the route's `can:` middleware, so an out-of-scope resource stays a 404.
 */
class RequireStepUp
{
    /** Laravel's own key, as used by its `password.confirm` middleware. */
    public const SESSION_KEY = 'auth.password_confirmed_at';

    public const MAX_AGE_SECONDS = 300;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $confirmedAt = $request->session()->get(self::SESSION_KEY);

        if (is_int($confirmedAt) && now()->getTimestamp() - $confirmedAt <= self::MAX_AGE_SECONDS) {
            return $next($request);
        }

        return Problem::response(423, 'Locked', trans()->string('emailsquire.problem.step_up_required'), 'step_up_required');
    }
}
