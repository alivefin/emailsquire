<?php

use App\Http\Controllers\Auth\CsrfController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Middleware\EnsureSameOrigin;
use Illuminate\Support\Facades\Route;

// Mounted under /api/v1. The SPA authenticates with the session cookie, so these routes use the
// `web` group (sessions, CSRF), not the stateless `api` group.
Route::middleware(['web', EnsureSameOrigin::class])->group(function () {
    Route::get('auth/csrf', CsrfController::class);
    Route::post('auth/setup', SetupController::class)->middleware('throttle:10,1');

    Route::middleware(['auth', AbsoluteSessionLimit::class])->group(function () {
        Route::get('me', MeController::class);
    });
});
