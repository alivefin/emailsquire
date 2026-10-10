<?php

use App\Http\Controllers\AddressCheckController;
use App\Http\Controllers\AddressController;
use App\Http\Controllers\Auth\ConfirmPasswordController;
use App\Http\Controllers\Auth\CsrfController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\LogoutAllController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Auth\SetupController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\BlockedAddressController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DomainAccessController;
use App\Http\Controllers\DomainController;
use App\Http\Controllers\DomainInvitationController;
use App\Http\Controllers\DomainNameController;
use App\Http\Controllers\DomainSettingsController;
use App\Http\Controllers\ForwardingController;
use App\Http\Controllers\MailboxBulkController;
use App\Http\Controllers\MailboxController;
use App\Http\Controllers\MailboxPasswordController;
use App\Http\Controllers\MailboxStateController;
use App\Http\Controllers\MailboxUsageController;
use App\Http\Controllers\Me\PasswordController;
use App\Http\Controllers\Me\RecoveryCodesController;
use App\Http\Controllers\Me\TwoFactorController;
use App\Http\Controllers\PinnedDomainController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGrantController;
use App\Http\Controllers\UserInvitationController;
use App\Http\Middleware\AbsoluteSessionLimit;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureSameOrigin;
use App\Http\Middleware\RequireTwoFactor;
use App\MailServer\Models\Alias;
use App\MailServer\Models\Domain;
use App\MailServer\Models\Mailbox;
use App\Models\BlockedAddress;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

// Mounted under /api/v1. The SPA authenticates with the session cookie, so these routes use the
// `web` group (sessions, CSRF), not the stateless `api` group.
Route::middleware(['web', EnsureSameOrigin::class])->group(function () {
    Route::get('auth/csrf', CsrfController::class);
    // Fortify's controller and login pipeline (see AppServiceProvider); its own routes are off.
    // No guest middleware: it answers a signed-in client with a redirect. Signing in again is harmless.
    Route::post('auth/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:login');
    Route::post('auth/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth:web');
    Route::post('auth/setup', SetupController::class)->middleware('throttle:10,1');
    // Second sign-in step for a user with two-factor; POST /auth/login left `login.id` in the session.
    Route::post('auth/two-factor-challenge', TwoFactorChallengeController::class)->middleware('throttle:two-factor');
    // Forgotten password: a link by email, then a new password. Neither signs anyone in.
    Route::post('auth/forgot-password', ForgotPasswordController::class)->middleware('throttle:forgot-password');
    Route::post('auth/reset-password', ResetPasswordController::class)->middleware('throttle:reset-password');
    // An invitation link: the token is the credential, sent in the body (never in a URL a server
    // logs). Accepting signs a new person in; an existing user must already be signed in as the
    // invited email.
    Route::post('auth/invitations/lookup', [InvitationController::class, 'show'])->middleware('throttle:invitation-lookup');
    Route::post('auth/invitations/accept', [InvitationController::class, 'accept'])->middleware('throttle:invitation-accept');

    // EnsureActiveUser: a session of a user deactivated since sign-in answers 401 and ends.
    // RequireTwoFactor: a user who must have two-factor and hasn't confirmed it reaches only their
    // profile, two-factor setup and auth/* until they have.
    Route::middleware(['auth', EnsureActiveUser::class, AbsoluteSessionLimit::class, RequireTwoFactor::class])->group(function () {
        Route::get('me', MeController::class);
        Route::patch('me', [MeController::class, 'update']);
        // Step-up: opens the 300 s window that `step-up` routes need (RequireStepUp).
        Route::post('auth/confirm-password', ConfirmPasswordController::class)->middleware('throttle:step-up');
        // "Sign out everywhere": ends the user's other sessions.
        Route::post('auth/logout-all', LogoutAllController::class)->middleware('throttle:logout-all');
        // Own password change; it checks the current password, so it shares step-up's guess limit.
        Route::put('me/password', [PasswordController::class, 'update'])->middleware('throttle:step-up');

        // The signed-in user's own two-factor; /me routes need no can: (RouteAuthorizationTest). Setup
        // and confirm need step-up too, so a stolen session can neither read the secret of an
        // unconfirmed setup nor confirm it with an attacker's app; one window covers the whole flow.
        Route::post('me/two-factor', [TwoFactorController::class, 'store'])->middleware('step-up');
        Route::get('me/two-factor/setup', [TwoFactorController::class, 'setup'])->middleware('step-up');
        Route::post('me/two-factor/confirm', [TwoFactorController::class, 'confirm'])->middleware('step-up');
        Route::delete('me/two-factor', [TwoFactorController::class, 'destroy'])->middleware('step-up');
        Route::get('me/recovery-codes', [RecoveryCodesController::class, 'index'])->middleware('step-up');
        Route::post('me/recovery-codes', [RecoveryCodesController::class, 'store'])->middleware('step-up');

        // Every route below carries a can: middleware (RouteAuthorizationTest enforces it) and a row in
        // the role matrix (Tests\Support\AuthorizationMatrix). Collections every signed-in user may open
        // (viewAny) hold only the domains they have a role on (DomainAccess::scope()).
        Route::get('dashboard', DashboardController::class)->middleware('can:viewAny,'.Domain::class);
        Route::get('search', SearchController::class)->middleware('can:viewAny,'.Domain::class);
        Route::get('addresses', AddressController::class)->middleware('can:viewAny,'.Domain::class);
        Route::get('me/pinned-domains', [PinnedDomainController::class, 'index'])->middleware('can:viewAny,'.Domain::class);
        Route::put('me/pinned-domains/{domain}', [PinnedDomainController::class, 'store'])->middleware('can:view,domain');
        Route::delete('me/pinned-domains/{domain}', [PinnedDomainController::class, 'destroy'])->middleware('can:view,domain');

        Route::get('domains', [DomainController::class, 'index'])->middleware('can:viewAny,'.Domain::class);
        Route::post('domains', [DomainController::class, 'store'])->middleware('can:create,'.Domain::class);
        Route::get('domains/names', DomainNameController::class)->middleware('can:viewAny,'.Domain::class);
        // can: before step-up, so an out-of-scope domain stays a 404 rather than a 423.
        Route::delete('domains/{domain}', [DomainController::class, 'destroy'])->middleware(['can:delete,domain', 'step-up']);
        Route::get('domains/{domain}/addresses/check', AddressCheckController::class)->middleware('can:view,domain');
        // Limits: anyone with a role reads them, only admins change them (no step-up).
        Route::get('domains/{domain}/settings', [DomainSettingsController::class, 'show'])->middleware('can:viewLimits,domain');
        Route::patch('domains/{domain}/settings', [DomainSettingsController::class, 'update'])->middleware('can:updateLimits,domain');
        // Access: anyone with a role sees who has it. {user} is a user id; one without a grant on this
        // domain answers 404 like an id nobody has, from the policy, before step-up.
        Route::get('domains/{domain}/access', [DomainAccessController::class, 'index'])->middleware('can:view,domain');
        Route::patch('domains/{domain}/access/{user}', [DomainAccessController::class, 'update'])
            ->whereNumber('user')->middleware(['can:changeAccess,domain,user', 'step-up']);
        Route::delete('domains/{domain}/access/{user}', [DomainAccessController::class, 'destroy'])
            ->whereNumber('user')->middleware(['can:removeAccess,domain,user', 'step-up']);
        // Invitations to a domain: sharing. {invitation} must be one of this domain's (404 otherwise,
        // from the policy, before step-up). Creating and resending share one hourly limit per user.
        Route::post('domains/{domain}/access/invitations', [DomainInvitationController::class, 'store'])
            ->middleware(['can:share,domain', 'step-up', 'throttle:invitations']);
        Route::post('domains/{domain}/access/invitations/{invitation}/resend', [DomainInvitationController::class, 'resend'])
            ->whereNumber('invitation')->middleware(['can:manageInvitation,domain,invitation', 'step-up', 'throttle:invitations']);
        Route::delete('domains/{domain}/access/invitations/{invitation}', [DomainInvitationController::class, 'destroy'])
            ->whereNumber('invitation')->middleware(['can:manageInvitation,domain,invitation', 'step-up']);

        // Invitations from the Users screen: server admins, to the server or to any domain.
        Route::post('users/invitations', [UserInvitationController::class, 'store'])
            ->middleware(['can:create,'.User::class, 'step-up', 'throttle:invitations']);
        Route::post('users/invitations/{invitation}/resend', [UserInvitationController::class, 'resend'])
            ->whereNumber('invitation')->middleware(['can:create,'.User::class, 'step-up', 'throttle:invitations']);
        Route::delete('users/invitations/{invitation}', [UserInvitationController::class, 'destroy'])
            ->whereNumber('invitation')->middleware(['can:create,'.User::class, 'step-up']);

        // The Users screen: server admins; to anyone else the users and the screen do not exist (404).
        // {user} is a user id. PATCH checks step-up itself: a name alone needs none, an email, role or
        // status does (UserController::update). Grants and the two-factor reset change the user, so
        // they share the update policy.
        Route::get('users', [UserController::class, 'index'])->middleware('can:viewAny,'.User::class);
        Route::get('users/{user}', [UserController::class, 'show'])->whereNumber('user')->middleware('can:view,user');
        Route::patch('users/{user}', [UserController::class, 'update'])->whereNumber('user')->middleware('can:update,user');
        Route::delete('users/{user}', [UserController::class, 'destroy'])->whereNumber('user')->middleware(['can:delete,user', 'step-up']);
        Route::post('users/{user}/grants', [UserGrantController::class, 'store'])->whereNumber('user')->middleware(['can:update,user', 'step-up']);
        Route::delete('users/{user}/grants/{domain}', [UserGrantController::class, 'destroy'])->whereNumber('user')->middleware(['can:update,user', 'step-up']);
        Route::post('users/{user}/two-factor/reset', [UserController::class, 'resetTwoFactor'])->whereNumber('user')->middleware(['can:update,user', 'step-up']);

        Route::scopeBindings()->group(function () {
            Route::get('domains/{domain}/mailboxes', [MailboxController::class, 'index'])->middleware('can:viewAny,'.Mailbox::class.',domain');
            Route::post('domains/{domain}/mailboxes', [MailboxController::class, 'store'])->middleware('can:create,'.Mailbox::class.',domain');
            Route::get('domains/{domain}/mailboxes/{mailbox}', [MailboxController::class, 'show'])->middleware('can:view,mailbox');
            Route::patch('domains/{domain}/mailboxes/{mailbox}', [MailboxController::class, 'update'])->middleware('can:update,mailbox');
            Route::get('domains/{domain}/mailboxes/{mailbox}/usage', MailboxUsageController::class)->middleware('can:view,mailbox');
            Route::post('domains/{domain}/mailboxes/{mailbox}/password', MailboxPasswordController::class)->middleware('can:update,mailbox');
            Route::post('domains/{domain}/mailboxes/{mailbox}/disable', [MailboxStateController::class, 'disable'])->middleware('can:update,mailbox');
            Route::post('domains/{domain}/mailboxes/{mailbox}/enable', [MailboxStateController::class, 'enable'])->middleware('can:update,mailbox');
            Route::delete('domains/{domain}/mailboxes/{mailbox}', [MailboxStateController::class, 'destroy'])->middleware('can:delete,mailbox');
            Route::post('domains/{domain}/mailboxes/bulk', MailboxBulkController::class)->middleware('can:bulkUpdate,'.Mailbox::class.',domain');
        });

        Route::get('domains/{domain}/forwarding', [ForwardingController::class, 'index'])->middleware('can:viewAny,'.Alias::class.',domain');
        Route::put('domains/{domain}/forwarding/{local}', [ForwardingController::class, 'update'])->middleware('can:update,'.Alias::class.',domain');
        Route::delete('domains/{domain}/forwarding/{local}', [ForwardingController::class, 'destroy'])->middleware('can:delete,'.Alias::class.',domain');

        Route::get('blocked-addresses', [BlockedAddressController::class, 'index'])->middleware('can:viewAny,'.BlockedAddress::class);
        Route::post('blocked-addresses', [BlockedAddressController::class, 'store'])->middleware('can:create,'.BlockedAddress::class);
        Route::delete('blocked-addresses/{address}', [BlockedAddressController::class, 'destroy'])->middleware('can:delete,'.BlockedAddress::class);
    });
});
