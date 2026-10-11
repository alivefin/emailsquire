<?php

namespace App\MailServer;

use App\Auth\Mailbox\MailboxPrincipal;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Write side of the audit log: one `audit_log` row per change. The activity log and the dashboard read
 * the rows through App\MailServer\Queries\AuditLog and AuditVisibility; every action written here is
 * listed in AuditActions.
 */
class AuditLog
{
    /** Actions written outside any guide transaction: there is no guide change to commit alongside them. */
    private const OUTSIDE_TRANSACTION = ['admin.create', 'admin.setup'];

    /**
     * Must run inside the transaction that makes the change, so both commit or neither does.
     *
     * $actor names who acted when nobody is signed in on this request but the actor is known, such
     * as a user resetting their password with an emailed link; otherwise the signed-in user is. A
     * mailbox owner signed in to the portal acts as `mailbox`, keyed by the mailbox's stored address.
     *
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, string $objectType, string $objectKey, ?string $domainName = null, array $changes = [], ?User $actor = null): void
    {
        if (DB::transactionLevel() === 0 && ! in_array($action, self::OUTSIDE_TRANSACTION, true)) {
            throw new LogicException("Audit entry {$action} must be written inside the transaction of the change it records.");
        }

        $user = $actor ?? $this->signedIn();
        if ($user instanceof MailboxPrincipal) {
            $actorType = 'mailbox';
            $actorId = $user->address;
        } elseif ($user instanceof User) {
            $actorType = 'user';
            $actorId = (string) $user->id;
        } elseif (app()->runningInConsole()) {
            $actorType = 'cli';
            $actorId = $this->osUser();
        } else {
            $actorType = 'system';
            $actorId = null;
        }

        AuditEntry::create([
            'occurred_at' => now(),
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'ip' => request()->ip(),
            'action' => $action,
            'object_type' => $objectType,
            'object_key' => $objectKey,
            'domain_name' => $domainName,
            'changes' => $changes === [] ? null : $this->redact($changes),
        ]);
    }

    /** Who is signed in on this request: a console user, or on the portal's routes the mailbox. */
    private function signedIn(): User|MailboxPrincipal|null
    {
        if (Auth::getDefaultDriver() === 'mailbox') {
            $mailbox = Auth::guard('mailbox')->user();

            return $mailbox instanceof MailboxPrincipal ? $mailbox : null;
        }

        return Auth::user();
    }

    private function osUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $info = posix_getpwuid(posix_geteuid());
            if (is_array($info)) {
                return $info['name'];
            }
        }

        return get_current_user();
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match('/pass|secret|token|hash/i', $key) === 1) {
                $data[$key] = '[redacted]';
            } elseif (is_array($value)) {
                $data[$key] = $this->redact($value);
            }
        }

        return $data;
    }
}
