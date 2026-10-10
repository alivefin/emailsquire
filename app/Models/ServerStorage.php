<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServerStorage extends Model
{
    protected $table = 'server_storage';

    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'total_bytes' => 'integer',
            'free_bytes' => 'integer',
            'checked_at' => 'datetime',
        ];
    }
}
