<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language of validation and auth messages: the signed-in user's locale, then the first
 * language of Accept-Language (`fi` or `fi-*` is Finnish), then English. Nothing else is honoured.
 */
class SetLocale
{
    public const SUPPORTED = ['en', 'fi'];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $stored = $user instanceof User ? $user->locale : null;

        App::setLocale(
            is_string($stored) && in_array($stored, self::SUPPORTED, true)
                ? $stored
                : self::fromHeader($request->header('Accept-Language')),
        );

        return $next($request);
    }

    private static function fromHeader(?string $header): string
    {
        $first = trim(explode(',', (string) $header)[0]);
        $tag = trim(explode(';', $first)[0]);

        return preg_match('/^fi(?:[-_]|$)/i', $tag) === 1 ? 'fi' : 'en';
    }
}
