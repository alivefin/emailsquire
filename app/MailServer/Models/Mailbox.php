<?php

namespace App\MailServer\Models;

use App\Auth\DomainAccess;
use App\MailServer\GuideSchema;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $domain_id
 * @property string $email
 */
class Mailbox extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    protected $hidden = ['password'];

    public function getTable(): string
    {
        return GuideSchema::table('virtual_users');
    }

    /**
     * The mailboxes of the domains $user has a role on (DomainAccess).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(DomainAccess::class)->scope($user)->constrain($query, $this->qualifyColumn('domain_id'));
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }
}
