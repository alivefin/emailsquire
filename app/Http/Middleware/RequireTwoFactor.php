<?php

namespace App\Http\Middleware;

use App\Http\Problem;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Required two-factor (`emailsquire.require_two_factor`): a user it applies to who has not confirmed
 * two-factor gets 403 `two_factor_required` on every authenticated API route except their profile,
 * two-factor setup and the auth routes, so the only thing they can do is finish setup.
 */
class RequireTwoFactor
{
    /** Paths a blocked user can still reach (GET and PATCH /me, setup, step-up, password). */
    public const ALLOWED = [
        'api/v1/me',
        'api/v1/me/two-factor',
        'api/v1/me/two-factor/*',
        'api/v1/me/recovery-codes',
        'api/v1/me/password',
        'api/v1/auth/*',
    ];

    /** Whether the setting asks this user for two-factor: `admins` (the default), `everyone` or `off`. */
    public static function requiredFor(User $user): bool
    {
        return match (config('emailsquire.require_two_factor')) {
            'off' => false,
            'everyone' => true,
            // Any other value reads as the default, so a typo never switches the requirement off.
            default => $user->is_server_admin,
        };
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User
            && ! $user->hasEnabledTwoFactorAuthentication()
            && self::requiredFor($user)
            && ! $request->is(...self::ALLOWED)) {
            return Problem::response(403, 'Forbidden', trans()->string('emailsquire.problem.two_factor_required'), 'two_factor_required');
        }

        return $next($request);
    }
}
