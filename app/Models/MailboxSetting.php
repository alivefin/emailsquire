<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailboxSetting extends Model
{
    protected $table = 'mailbox_settings';

    protected $primaryKey = 'mailbox_email';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'allow_user_forwarding' => 'boolean',
            'allow_user_aliases' => 'boolean',
            'allow_external_forwarding' => 'boolean',
        ];
    }
}
