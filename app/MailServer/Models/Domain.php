<?php

namespace App\MailServer\Models;

use App\MailServer\Address\AddressNormalizer;
use App\MailServer\Address\InvalidAddress;
use App\MailServer\GuideSchema;
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

        $local = is_scalar($value) ? strtolower((string) $value) : '';

        return Mailbox::query()
            ->where('domain_id', $this->id)
            ->where('email', $local.'@'.$this->name)
            ->first();
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
