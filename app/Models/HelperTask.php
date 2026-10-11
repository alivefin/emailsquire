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
 * @property string|null $note
 * @property array<string, mixed>|null $result public data a helper hands back (the DKIM helper's selector and TXT); never a key
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
            'result' => 'array',
            'run_after' => 'datetime',
        ];
    }
}
