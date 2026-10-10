<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PinnedDomain extends Model
{
    protected $table = 'pinned_domains';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'domain_id' => 'integer',
            'position' => 'integer',
        ];
    }
}
