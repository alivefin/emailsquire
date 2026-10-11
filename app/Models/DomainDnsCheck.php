<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The latest DNS health check of a guide domain (App\MailServer\Dns\DnsHealth), one row per domain.
 * It counts only while the domain id still carries `domain_name`.
 *
 * @property int $domain_id
 * @property string $domain_name
 * @property Carbon $checked_at
 * @property array<string, mixed> $results
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DomainDnsCheck extends Model
{
    protected $table = 'domain_dns_checks';

    protected $primaryKey = 'domain_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'checked_at' => 'datetime',
            'results' => 'array',
        ];
    }
}
