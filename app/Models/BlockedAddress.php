<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $address
 * @property string|null $reason
 * @property int|null $created_by
 * @property Carbon|null $created_at
 */
class BlockedAddress extends Model
{
    protected $table = 'blocked_addresses';

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
