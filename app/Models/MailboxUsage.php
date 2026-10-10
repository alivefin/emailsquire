<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxUsage extends Model
{
    protected $table = 'mailbox_usage';

    protected $primaryKey = 'email';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'used_kib' => 'integer',
            'limit_kib' => 'integer',
            'checked_at' => 'datetime',
        ];
    }
}
