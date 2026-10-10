<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class HelperTask extends Model
{
    protected $table = 'helper_tasks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'run_after' => 'datetime',
        ];
    }
}
