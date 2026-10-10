<?php

namespace App\MailServer\Repositories;

use App\MailServer\Address\EmailAddress;
use App\MailServer\AuditLog;
use App\MailServer\Exceptions\Conflict;
use App\Models\BlockedAddress;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class BlockedAddressRepository
{
    public function __construct(private readonly AuditLog $audit) {}

    /** @return LengthAwarePaginator<int, BlockedAddress> */
    public function paginate(?string $q, int $page, int $perPage): LengthAwarePaginator
    {
        $query = BlockedAddress::query()->with('creator')->orderByDesc('id');

        if ($q !== null && $q !== '') {
            $query->where('address', 'like', '%'.addcslashes(strtolower($q), '\\%_').'%');
        }

        return $query->paginate($perPage, ['*'], 'page', $page);
    }

    public function find(string $address): ?BlockedAddress
    {
        return BlockedAddress::query()->where('address', strtolower($address))->first();
    }

    public function block(EmailAddress $a, ?string $reason): BlockedAddress
    {
        $address = $a->address();
        $reason = $reason === null || trim($reason) === '' ? null : $reason;

        return DB::transaction(function () use ($a, $address, $reason) {
            if (BlockedAddress::query()->where('address', $address)->lockForUpdate()->exists()) {
                throw new Conflict('already_blocked', 'Already blocked.');
            }

            $blocked = new BlockedAddress;
            $blocked->forceFill([
                'address' => $address,
                'reason' => $reason,
                'created_by' => Auth::id(),
            ]);
            try {
                $blocked->save();
            } catch (QueryException $e) {
                // A concurrent request won the unique key between the check and the insert.
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new Conflict('already_blocked', 'Already blocked.');
                }
                throw $e;
            }

            $this->audit->record('blocked.create', 'blocked_address', $address, $a->domain->ascii, ['reason' => $reason]);

            return $blocked->load('creator');
        });
    }

    /** @param  string  $address  already normalised (lowercase) */
    public function unblock(string $address): void
    {
        DB::transaction(function () use ($address) {
            $blocked = BlockedAddress::query()->where('address', $address)->lockForUpdate()->firstOrFail();
            $blocked->delete();

            $at = strrpos($address, '@');
            $this->audit->record('blocked.delete', 'blocked_address', $address, $at === false ? null : substr($address, $at + 1));
        });
    }
}
