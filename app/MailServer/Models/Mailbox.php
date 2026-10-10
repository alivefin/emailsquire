<?php

namespace App\MailServer\Models;

use App\MailServer\GuideSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
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

    /** @return BelongsTo<Domain, $this> */
    public function domain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain_id');
    }
}
