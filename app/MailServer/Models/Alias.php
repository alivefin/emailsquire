<?php

namespace App\MailServer\Models;

use App\MailServer\GuideSchema;
use App\Policies\ForwardingPolicy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** The policy is named for the feature: aliases are only ever surfaced as forwarding. */
#[UsePolicy(ForwardingPolicy::class)]
class Alias extends Model
{
    public $timestamps = false;

    protected $guarded = ['*'];

    public function getTable(): string
    {
        return GuideSchema::table('virtual_aliases');
    }

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }
}
