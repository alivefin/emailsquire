<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A DKIM key of a guide domain, as the console knows it: its selector and public DNS record
 * (`v=DKIM1; k=rsa; p=…`), never the private key. `pending` keys are generated but not yet signing,
 * the `active` one is in rspamd's selector map, `retired` ones are gone. Like the other app rows keyed
 * by a guide domain id, a row counts only while that id still carries `domain_name`.
 *
 * @property int $id
 * @property int $domain_id
 * @property string $domain_name
 * @property string $selector
 * @property string $txt
 * @property 'pending'|'active'|'retired' $state
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DomainDkim extends Model
{
    public const STATES = ['pending', 'active', 'retired'];

    protected $table = 'domain_dkim';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'domain_id' => 'integer',
        ];
    }
}
