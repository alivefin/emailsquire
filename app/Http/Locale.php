<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Picks the language of a request: the signed-in user's locale, then the first language of
 * Accept-Language (`fi` or `fi-*` is Finnish), then English. Nothing else is honoured.
 */
final class Locale
{
    public const SUPPORTED = ['en', 'fi'];

    public static function resolve(Request $request): string
    {
        // The exception renderer calls this too, possibly because the database is down; asking
        // for the user would then throw again and escape as a bare 500. Fall back to the header.
        try {
            $user = $request->user();
        } catch (\Throwable) {
            $user = null;
        }
        $stored = $user instanceof User ? $user->locale : null;

        return is_string($stored) && in_array($stored, self::SUPPORTED, true)
            ? $stored
            : self::fromHeader($request->header('Accept-Language'));
    }

    /** Sets the app locale for this request; for code that answers before SetLocale has run. */
    public static function apply(Request $request): void
    {
        App::setLocale(self::resolve($request));
    }

    /** Tells caches the answer depends on Accept-Language; adds the token once, keeping others. */
    public static function vary(Response $response): Response
    {
        if (! in_array('Accept-Language', $response->getVary(), true)) {
            $response->setVary('Accept-Language', false);
        }

        return $response;
    }

    private static function fromHeader(?string $header): string
    {
        $first = trim(explode(',', (string) $header)[0]);
        $tag = trim(explode(';', $first)[0]);

        return preg_match('/^fi(?:[-_]|$)/i', $tag) === 1 ? 'fi' : 'en';
    }
}
