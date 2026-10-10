<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $type
 * @property string $address
 * @property string $domain_name
 * @property string $status
 * @property int $attempts
 * @property string|null $last_error
 * @property CarbonInterface $run_after
 * @property array<string, mixed>|null $payload
 */
class HelperTask extends Model
{
    protected $table = 'helper_tasks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'payload' => 'array',
            'run_after' => 'datetime',
        ];
    }
}
