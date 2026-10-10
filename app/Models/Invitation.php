<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An emailed invitation to a role on one guide domain (`manager` or `viewer`), or to be a server admin
 * (`server_admin`, no domain). Only the SHA-256 of the link's token is stored. Its state is worked out
 * by App\Auth\Invitations::state(); nothing else decides it.
 *
 * @property int $id
 * @property string $token_hash
 * @property string $email lowercased
 * @property int|null $domain_id
 * @property string|null $domain_name the name the domain had when invited to
 * @property string $role `server_admin`, `manager` or `viewer`
 * @property string|null $note
 * @property string $locale `en` or `fi`
 * @property string|null $name
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Invitation extends Model
{
    public const ROLES = ['server_admin', 'manager', 'viewer'];

    public const STATES = ['valid', 'expired', 'used', 'revoked'];

    protected $table = 'invitations';

    protected $guarded = ['*'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'invited_by' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
