<?php

use App\Http\Controllers\AddressCheckController;
use App\Http\Controllers\Auth\CsrfController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\BlockedAddressController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainNameController;
use App\Http\Controllers\MailboxBulkController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailboxPasswordController;
use App\Http\Controllers\MailboxStateController;
use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Middleware\EnsureSameOrigin;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\BlockedAddress;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

// Mounted under /api/v1. The SPA authenticates with the session cookie, so these routes use the
// `web` group (sessions, CSRF), not the stateless `api` group.
Route::middleware(['web', EnsureSameOrigin::class])->group(function () {
    Route::get('auth/csrf', CsrfController::class);
    // Fortify's controller and login pipeline (see AppServiceProvider); its own routes are off.
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])->middleware(['guest:web', 'throttle:login']);
    Route::post('auth/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:web');
    Route::post('auth/setup', SetupController::class)->middleware('throttle:10,1');

    Route::middleware(['auth', AbsoluteSessionLimit::class])->group(function () {
        Route::get('me', MeController::class);

        // Every route below carries a can: middleware (RouteAuthorizationTest enforces it).
        Route::get('domains', [DomainController::class, 'index'])->middleware('can:viewAny,'.Domain::class);
        Route::post('domains', [DomainController::class, 'store'])->middleware('can:create,'.Domain::class);
        Route::get('domains/names', DomainNameController::class)->middleware('can:viewAny,'.Domain::class);
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->middleware('can:delete,domain');
        Route::get('domains/{domain}/addresses/check', AddressCheckController::class)->middleware('can:view,domain');

        Route::scopeBindings()->group(function () {
            Route::get('domains/{domain}/mailboxes', [MailboxController::class, 'index'])->middleware('can:viewAny,'.Mailbox::class.',domain');
            Route::post('domains/{domain}/mailboxes', [MailboxController::class, 'store'])->middleware('can:create,'.Mailbox::class.',domain');
            Route::get('domains/{domain}/mailboxes/{mailbox}', [MailboxController::class, 'show'])->middleware('can:view,mailbox');
            Route::patch('domains/{domain}/mailboxes/{mailbox}', [MailboxController::class, 'update'])->middleware('can:update,mailbox');
            Route::post('domains/{domain}/mailboxes/{mailbox}/password', MailboxPasswordController::class)->middleware('can:update,mailbox');
            Route::post('domains/{domain}/mailboxes/{mailbox}/disable', [MailboxStateController::class, 'disable'])->middleware('can:update,mailbox');
            Route::post('domains/{domain}/mailboxes/{mailbox}/enable', [MailboxStateController::class, 'enable'])->middleware('can:update,mailbox');
            Route::delete('domains/{domain}/mailboxes/{mailbox}', [MailboxStateController::class, 'destroy'])->middleware('can:delete,mailbox');
            Route::post('domains/{domain}/mailboxes/bulk', MailboxBulkController::class)->middleware('can:bulkUpdate,'.Mailbox::class.',domain');
        });

        Route::get('blocked-addresses', [BlockedAddressController::class, 'index'])->middleware('can:viewAny,'.BlockedAddress::class);
        Route::post('blocked-addresses', [BlockedAddressController::class, 'store'])->middleware('can:create,'.BlockedAddress::class);
        Route::delete('blocked-addresses/{address}', [BlockedAddressController::class, 'destroy'])->middleware('can:delete,'.BlockedAddress::class);
    });
});
