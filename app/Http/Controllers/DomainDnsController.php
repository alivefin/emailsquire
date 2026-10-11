<?php

namespace App\Http\Controllers;

use App\MailServer\Dns\DnsHealth;
use App\MailServer\Models\Domain;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use LogicException;

/**
 * A domain's DNS health (spec decisions 7 to 10): MX, SPF, DKIM, DMARC and PTR for this server, with
 * MTA-STS and autoconfig as information.
 */
class DomainDnsController extends Controller
{
    /**
     * The latest stored check. A domain never checked gets one queued on this first read and answers
     * `result: null` with `checking: true` until it has run; `checking` is also true while a recheck
     * waits in the queue.
     *
     * Each check: `status` (`ok`, `warn`, `fail`, or `info` for MTA-STS and autoconfig), `found` (the
     * records seen), `expected` (the value looked for, where there is one), `hint_key` (what to tell
     * the user, translated by the SPA) and, when something should be published, `publish`: the record's
     * `name`, `type`, `value` (the text to copy) and `zone` (a zone-file line, long TXT split into
     * 255-byte strings). `dkim` also lists the console's pending and active keys in `selectors`, each
     * with whether DNS shows its record.
     *
     * @response array{result: array{checked_at: string, checks: array<string, array{status: 'ok'|'warn'|'fail'|'info', found: list<string>, expected?: string, hint_key: string, publish?: array{name: string, type: string, value: string, zone: string}, selectors?: list<array{selector: string, state: 'pending'|'active', published: bool, publish: array{name: string, type: string, value: string, zone: string}}>}>}|null, checking: bool}
     */
    public function show(Domain $domain, DnsHealth $dns): JsonResponse
    {
        $result = $dns->latest($domain);
        if ($result === null && ! $dns->isQueued($domain)) {
            $dns->queue($domain);
            $result = $dns->latest($domain); // a sync queue (e2e) has already run it
        }

        return response()->json(['result' => $result, 'checking' => $dns->isQueued($domain)]);
    }

    /**
     * Queues a new check now. One a minute per domain, whoever asks (429 `too_many_requests` with
     * `Retry-After`); a request refused by authorization costs nothing. Audited as `domain.dns_checked`.
     * Answers 202 with the same body as reading, which still holds the previous result while the
     * check waits in the queue.
     */
    public function recheck(Request $request, Domain $domain, DnsHealth $dns): JsonResponse
    {
        // The dns-recheck limiter (AppServiceProvider), counted here rather than by `throttle:`
        // middleware, which would run before authorization and let anyone use up a domain's minute.
        $limit = RateLimiter::limiter('dns-recheck');
        $limit = $limit === null ? null : $limit($request);
        if (! $limit instanceof Limit || ! is_string($limit->key)) {
            throw new LogicException('The dns-recheck limiter must give one Limit with a key.');
        }
        $key = 'dns-recheck:'.$limit->key;
        if (RateLimiter::tooManyAttempts($key, $limit->maxAttempts)) {
            throw new ThrottleRequestsException('Too Many Attempts.', headers: ['Retry-After' => RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, $limit->decaySeconds);

        $dns->recheck($domain);

        /**
         * @status 202
         *
         * @body array{result: array{checked_at: string, checks: array<string, array{status: 'ok'|'warn'|'fail'|'info', found: list<string>, expected?: string, hint_key: string, publish?: array{name: string, type: string, value: string, zone: string}, selectors?: list<array{selector: string, state: 'pending'|'active', published: bool, publish: array{name: string, type: string, value: string, zone: string}}>}>}|null, checking: bool}
         */
        return response()->json(['result' => $dns->latest($domain), 'checking' => $dns->isQueued($domain)], 202);
    }
}
