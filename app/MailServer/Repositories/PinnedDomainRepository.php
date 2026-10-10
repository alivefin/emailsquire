<?php

namespace App\MailServer\Repositories;

use App\MailServer\Models\Domain;
use App\Models\PinnedDomain;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Writes the per-user pinned domain list (an app table; the guide is only read for the domain). */
class PinnedDomainRepository
{
    public const MAX_PINS = 10;

    public function pin(int $userId, Domain $domain): void
    {
        DB::transaction(function () use ($userId, $domain) {
            // Lock this user's pins so two concurrent pins cannot both take the last slot.
            User::query()->whereKey($userId)->lockForUpdate()->first();
            $pins = PinnedDomain::query()->where('user_id', $userId);

            if ((clone $pins)->where('domain_id', $domain->id)->exists()) {
                return;
            }
            if ((clone $pins)->count() >= self::MAX_PINS) {
                throw ValidationException::withMessages(['domain' => __('emailsquire.domain.pin_limit', ['max' => self::MAX_PINS])]);
            }

            $max = (clone $pins)->max('position');
            PinnedDomain::create([
                'user_id' => $userId,
                'domain_id' => $domain->id,
                'domain_name' => $domain->name,
                'position' => (is_numeric($max) ? (int) $max : 0) + 1,
            ]);
        });
    }
}
