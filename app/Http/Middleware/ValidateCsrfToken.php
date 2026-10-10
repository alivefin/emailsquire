<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * Laravel skips CSRF verification under unit tests, so a "no token is 419" test could never fail.
 * Only the security tests flip emailsquire.testing.enforce_csrf; production never runs unit tests.
 *
 * It also drops Laravel 13's shortcut that waves through any `Sec-Fetch-Site: same-origin` request
 * without a token: the SPA always sends X-XSRF-TOKEN, so the token stays mandatory.
 */
class ValidateCsrfToken extends PreventRequestForgery
{
    protected function runningUnitTests()
    {
        return app()->runningUnitTests() && ! config('emailsquire.testing.enforce_csrf', false);
    }

    protected function hasValidOrigin($request)
    {
        return false;
    }
}
