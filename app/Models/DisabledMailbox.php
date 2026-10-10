<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Hidden(['saved_password'])]
class DisabledMailbox extends Model
{
    protected $table = 'disabled_mailboxes';

    protected $primaryKey = 'mailbox_email';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'disabled_at' => 'datetime',
        ];
    }
}
