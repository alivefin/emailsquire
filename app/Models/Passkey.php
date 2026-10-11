<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Laravel\Passkeys\Passkey as PackagePasskey;

/**
 * A console user's passkey: laravel/passkeys' model (its `passkeys` table) plus `disabled_at`. The
 * stored WebAuthn credential record (public key, counter, user handle) is never serialised; the API
 * shows only App\Auth\Passkeys::present().
 *
 * @property Carbon|null $disabled_at
 */
class Passkey extends PackagePasskey
{
    /** @var list<string> */
    protected $hidden = ['credential'];

    /** No authenticator name from the AAGUID table: the API doesn't show one. */
    protected $appends = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return parent::casts() + [
            'user_id' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }

    /**
     * Passkeys that may sign in and count as a second factor.
     *
     * @param  Builder<self>  $query
     */
    public function scopeUsable(Builder $query): void
    {
        $query->whereNull('disabled_at');
    }
}
