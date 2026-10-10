<?php

namespace App\Http\Middleware;

use App\Http\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language of validation and auth messages (see Locale::resolve()). Runs in the `web`
 * group after the session starts, so the signed-in user's locale is known. Responses carry
 * `Vary: Accept-Language`.
 */
class SetLocale
{
    public const SUPPORTED = Locale::SUPPORTED;

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        Locale::apply($request);

        return Locale::vary($next($request));
    }
}
