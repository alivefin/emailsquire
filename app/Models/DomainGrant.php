<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A user's role on one guide domain: `manager` or `viewer` (a server admin needs none). `domain_name`
 * is the name the domain had when it was granted; DomainAccess honours the grant only while the guide
 * row with `domain_id` still carries that name.
 *
 * @property int $id
 * @property int $user_id
 * @property int $domain_id
 * @property string $domain_name
 * @property string $role `manager` or `viewer`
 * @property int|null $granted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DomainGrant extends Model
{
    public const ROLES = ['manager', 'viewer'];

    protected $table = 'domain_grants';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'domain_id' => 'integer',
            'granted_by' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
