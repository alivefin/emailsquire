<?php

namespace App\Auth;

use App\Mail\Notify;
use App\MailServer\AuditLog;
use App\Models\User;
use Illuminate\Auth\Passwords\PasswordBroker;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Password and session changes a user makes to their own account. Each change, the sessions it ends
 * and its audit row commit together; the security notice is queued after the commit. Audit rows name
 * the user by email and never carry a password or a token.
 */
final class AccountPassword
{
    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Sets a new password with a forgotten-password token (the `users` broker) and ends every session
     * of that user. Signs nobody in. Null for a wrong, spent or expired token, an unknown email, and a
     * user who could not have asked for the link (deactivated, or setup not finished); the token is
     * spent in that last case all the same.
     */
    public function reset(string $email, string $token, string $password): ?User
    {
        $done = null;

        // A failed reset returns only after PasswordBroker::reset's timebox (200 ms), so its timing
        // doesn't tell whether a token row exists for that email (account enumeration).
        $status = $this->broker()->reset(
            ['email' => Str::lower($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password) use (&$done): void {
                if ($user->status !== 'active' || $user->password_set_at === null) {
                    return;
                }

                DB::transaction(function () use ($user, $password): void {
                    $user->forceFill(['password' => $password, 'password_set_at' => now(), 'remember_token' => null])->save();
                    OtherSessions::end($user, null);
                    // Nobody is signed in on this request; the user acted, with the emailed link.
                    $this->audit->record('user.password_reset', 'user', $user->email, actor: $user);
                });
                $done = $user;
            },
        );

        if ($status !== Password::PASSWORD_RESET || ! $done instanceof User) {
            return null;
        }
        Notify::security($done, 'password_reset');

        return $done;
    }

    /**
     * Changes the signed-in user's password (the caller has checked the current one). This browser
     * gets a new session id and stays signed in; every other session of the user ends.
     */
    public function change(User $user, string $password, Session $session): void
    {
        DB::transaction(function () use ($user, $password, $session): void {
            $user->forceFill(['password' => $password, 'password_set_at' => now(), 'remember_token' => null])->save();
            // The old id is destroyed. AuthenticateSession stores the new password hash in this session
            // after the request, so it stays valid while the others are gone.
            $session->regenerate(true);
            OtherSessions::end($user, $session->getId());
            $this->audit->record('user.password_changed', 'user', $user->email);
        });
        Notify::security($user, 'password_changed');
    }

    /** "Sign out everywhere": ends every session of the user but $keepSessionId; answers how many. */
    public function signOutOthers(User $user, string $keepSessionId): int
    {
        $count = DB::transaction(function () use ($user, $keepSessionId): int {
            $count = OtherSessions::end($user, $keepSessionId);
            $this->audit->record('user.sessions_revoked', 'user', $user->email, null, ['count' => $count]);

            return $count;
        });
        Notify::security($user, 'sessions_revoked');

        return $count;
    }

    private function broker(): PasswordBroker
    {
        /** @var PasswordBroker */
        return Password::broker('users');
    }
}
