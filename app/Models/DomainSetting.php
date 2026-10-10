<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Per-domain settings in the app schema, keyed by the guide domain id. `domain_name` is the name the
 * domain had when the row was written; the row counts only while the guide row still carries it
 * (a mismatch is an app.orphans finding). `null` limits mean unlimited.
 *
 * @property int $domain_id
 * @property string $domain_name
 * @property int|null $max_mailboxes
 * @property int|null $max_quota_bytes_total
 * @property bool $allow_user_forwarding
 * @property bool $allow_user_aliases
 * @property bool $allow_external_forwarding
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DomainSetting extends Model
{
    protected $table = 'domain_settings';

    protected $primaryKey = 'domain_id';

    public $incrementing = false;

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
            'max_mailboxes' => 'integer',
            'max_quota_bytes_total' => 'integer',
            'allow_user_forwarding' => 'boolean',
            'allow_user_aliases' => 'boolean',
            'allow_external_forwarding' => 'boolean',
        ];
    }
}
