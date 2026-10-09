<?php

namespace App\MailServer\Models;

use App\MailServer\GuideSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Domain extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return GuideSchema::table('virtual_domains');
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
