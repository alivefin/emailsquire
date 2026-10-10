<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** A debug page leaks config and secrets; refuse to serve at all rather than risk it. */
class RefuseDebugInProduction
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.env') === 'production' && config('app.debug')) {
            return new Response(
                "Refusing to serve: APP_DEBUG is on in production. Set APP_DEBUG=false.\n",
                503,
                ['Content-Type' => 'text/plain; charset=UTF-8'],
            );
        }

        return $next($request);
    }
}
