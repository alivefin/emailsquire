<?php

namespace App\MailServer;

use App\Doctor\Support\AliasMaps;
use App\MailServer\Exceptions\Conflict;
use Illuminate\Support\Facades\Cache;

/**
 * Whether this server can take a catch-all (`@domain`) safely: doctor's `postfix.alias_maps` answer
 * (App\Doctor\Support\AliasMaps), read as the app's own user and remembered for 10 minutes. A
 * main.cf the app can't find or read counts as unsupported: without seeing the email2email map, a
 * catch-all might take the mail of every existing mailbox.
 */
class CatchAllSupport
{
    public const CACHE_KEY = 'emailsquire:catch-all-support';

    public const TTL_SECONDS = 600;

    public function supported(): bool
    {
        return $this->state() === AliasMaps::SUPPORTED;
    }

    /** Refuses with 409 `catch_all_unsupported`, saying why, unless the server supports a catch-all. */
    public function assertSupported(): void
    {
        $state = $this->state();
        if ($state === AliasMaps::SUPPORTED) {
            return;
        }

        $reason = $state === AliasMaps::MISSING ? 'catch_all_unsupported' : 'catch_all_unreadable';
        throw new Conflict('catch_all_unsupported', trans()->string("emailsquire.forwarding.{$reason}"));
    }

    /** @return AliasMaps::SUPPORTED|AliasMaps::MISSING|AliasMaps::NOT_FOUND|AliasMaps::UNREADABLE */
    private function state(): string
    {
        $cached = Cache::get(self::CACHE_KEY);
        if (in_array($cached, [AliasMaps::SUPPORTED, AliasMaps::MISSING, AliasMaps::NOT_FOUND, AliasMaps::UNREADABLE], true)) {
            return $cached;
        }

        $state = AliasMaps::inspect(config('emailsquire.paths.postfix_main_cf'));
        Cache::put(self::CACHE_KEY, $state, self::TTL_SECONDS);

        return $state;
    }
}
