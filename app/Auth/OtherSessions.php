<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ends a user's other console sessions by deleting their rows from the database session table (the
 * production driver, see docs/backend-plan.md); with any other driver there are no rows to delete.
 * Used by a password change, a password reset, a setup link and "sign out everywhere". A mailbox's
 * portal sessions end the same way, by `mailbox_address` (GuardSessionHandler), after a portal
 * password change.
 */
final class OtherSessions
{
    /**
     * Deletes every `sessions` row of $user except $keepSessionId (null keeps none) and answers how
     * many it deleted.
     *
     * A browser halfway through a two-factor sign-in has no `user_id` yet (the login waits under
     * `login.id` in its payload), so it is not deleted; it still needs the code and expires in 5 minutes.
     */
    public static function end(User $user, ?string $keepSessionId): int
    {
        $query = DB::connection(self::connection())
            ->table(config()->string('session.table'))
            ->where('user_id', $user->getKey());
        if ($keepSessionId !== null) {
            $query->where('id', '!=', $keepSessionId);
        }

        return $query->delete();
    }

    /**
     * Deletes every portal session row of the mailbox stored as $address except $keepSessionId, and
     * answers how many it deleted. Console rows have no `mailbox_address`. The column's collation
     * folds case, as the guide's does, so one mailbox's rows match whatever spelling wrote them.
     */
    public static function endMailbox(string $address, string $keepSessionId): int
    {
        return DB::connection(self::connection())
            ->table(config()->string('session.table'))
            ->where('mailbox_address', $address)
            ->where('id', '!=', $keepSessionId)
            ->delete();
    }

    private static function connection(): ?string
    {
        $connection = config('session.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }
}
