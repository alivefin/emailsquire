<?php

namespace App\MailServer;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use LogicException;

class AuditLog
{
    /** Actions written outside any guide transaction: there is no guide change to commit alongside them. */
    private const OUTSIDE_TRANSACTION = ['admin.create', 'admin.setup'];

    /**
     * Must run inside the transaction that makes the change, so both commit or neither does.
     *
     * @param  array<string, mixed>  $changes
     */
    public function record(string $action, string $objectType, string $objectKey, ?string $domainName = null, array $changes = []): void
    {
        if (DB::transactionLevel() === 0 && ! in_array($action, self::OUTSIDE_TRANSACTION, true)) {
            throw new LogicException("Audit entry {$action} must be written inside the transaction of the change it records.");
        }

        $user = Auth::user();
        if ($user !== null) {
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
