<?php

namespace App\Http\Middleware;

use App\Http\Locale;
use App\Http\Problem;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Defence in depth beside CSRF tokens: refuses state-changing requests the browser marks as cross-site. */
class EnsureSameOrigin
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        $origin = $request->headers->get('Origin');

        $crossOrigin = $fetchSite !== null
            ? ! in_array($fetchSite, ['same-origin', 'none'], true)
            : ($origin !== null && $origin !== $request->getSchemeAndHttpHost());

        if ($crossOrigin) {
            // Answers before SetLocale has run, so pick the language here.
            Locale::apply($request);

            return Problem::response(403, 'Forbidden', __('emailsquire.problem.cross_origin'), 'cross_origin');
        }

        return $next($request);
    }
}
