<?php

namespace App\MailServer\Exceptions;

use App\MailServer\QuotaFormat;
use RuntimeException;

/**
 * A write would take a domain past its mailbox count or total storage limit; rendered as 422
 * `domain_limit`. $limit is `mailboxes` or `quota`; $max is the limit (a count, or bytes).
 */
class DomainLimitExceeded extends RuntimeException
{
    public function __construct(public readonly string $limit, public readonly int $max)
    {
        parent::__construct("Domain limit {$limit} ({$max}) reached.");
    }

    /**
     * The request field the refusal belongs to, so the console can show it there: the address field
     * (`local`) of the create form for the mailbox count, `quota_bytes` for total storage (create,
     * PATCH and bulk all send it).
     */
    public function field(): string
    {
        return $this->limit === 'mailboxes' ? 'local' : 'quota_bytes';
    }

    /** In the current locale; built when rendered, after the request's locale is applied. */
    public function detail(): string
    {
        return $this->limit === 'mailboxes'
            ? trans_choice('emailsquire.domain_limit.mailboxes', $this->max, ['max' => $this->max])
            : __('emailsquire.domain_limit.quota', ['max' => QuotaFormat::size($this->max)]);
    }
}
