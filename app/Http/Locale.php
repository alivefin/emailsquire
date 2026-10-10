<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

/**
 * Picks the language of a request: the signed-in user's locale, then the first language of
 * Accept-Language (`fi` or `fi-*` is Finnish), then English. Nothing else is honoured.
 */
final class Locale
{
    public const SUPPORTED = ['en', 'fi'];

    public static function resolve(Request $request): string
    {
        $user = $request->user();
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

    private static function fromHeader(?string $header): string
    {
        $first = trim(explode(',', (string) $header)[0]);
        $tag = trim(explode(';', $first)[0]);

        return preg_match('/^fi(?:[-_]|$)/i', $tag) === 1 ? 'fi' : 'en';
    }
}
