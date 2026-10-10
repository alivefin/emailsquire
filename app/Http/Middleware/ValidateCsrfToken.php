<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Laravel skips CSRF verification under console unit tests (console and env 'testing'; the parent
 * keeps both checks), so a "no token is 419" test could never fail. Only the security tests flip
 * emailsquire.testing.enforce_csrf. A web request never gets the bypass, whatever APP_ENV says.
 *
 * It also drops Laravel 13's shortcut that waves through any `Sec-Fetch-Site: same-origin` request
 * without a token: the SPA always sends X-XSRF-TOKEN, so the token stays mandatory.
 */
class ValidateCsrfToken extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return parent::runningUnitTests() && ! config('emailsquire.testing.enforce_csrf', false);
    }

    protected function hasValidOrigin($request)
    {
        return false;
    }
}
