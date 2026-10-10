<?php

namespace App\MailServer\Models;

use App\Auth\DomainAccess;
use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\GuideSchema;
use App\MailServer\Queries\MailboxLookup;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 */
class Domain extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return GuideSchema::table('virtual_domains');
    }

    public function getRouteKeyName(): string
    {
        return 'name';
    }

    /** Routes carry the domain name, in any case or as IDN; a malformed one is simply not found. */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        try {
            $ascii = app(AddressNormalizer::class)->domain(is_scalar($value) ? (string) $value : '')->ascii;
        } catch (InvalidAddress) {
            return null;
        }

        return $this->newQuery()->where($field ?? 'name', $ascii)->first();
    }

    /** Scoped binding: `{mailbox}` is the local part, and only this domain's own mailbox matches. */
    public function resolveChildRouteBinding($childType, $value, $field): ?Model
    {
        if ($childType !== 'mailbox') {
            return parent::resolveChildRouteBinding($childType, $value, $field);
        }

        try {
            $normalizer = app(AddressNormalizer::class);
            $address = $normalizer->addressIn(is_scalar($value) ? (string) $value : '', $normalizer->domain($this->name));
        } catch (InvalidAddress) {
            return null;
        }

        return app(MailboxLookup::class)->inDomain($this, $address);
    }

    /**
     * The domains $user has a role on (DomainAccess).
     *
     * @param  Builder<self>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        app(DomainAccess::class)->scope($user)->constrain($query, $this->qualifyColumn('id'));
    }

    /** @return HasMany<Mailbox, $this> */
    public function mailboxes(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'domain_id');
    }

    /** @return HasMany<Alias, $this> */
    public function aliases(): HasMany
    {
        return $this->hasMany(Alias::class, 'domain_id');
    }
}
