<?php

namespace App\MailServer;

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DoveadmException;
use Illuminate\Support\Facades\Log;

class MailboxSessions
{
    private const CHUNK = 50;

    public function __construct(private readonly DoveadmClient $doveadm) {}

    /** Ends live sessions for the addresses. Best effort: it never throws, and never logs an address. */
    public function kick(string ...$addresses): void
    {
        foreach (array_chunk($addresses, self::CHUNK) as $chunk) {
            try {
                $this->doveadm->call('kick', ['mask' => $chunk]);
            } catch (DoveadmException) {
                Log::warning('doveadm kick failed', ['count' => count($chunk)]);
            }
        }
    }
}
