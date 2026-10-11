<?php

namespace App\MailServer\Dns;

use App\Jobs\CheckDomainDns;
use App\MailServer\AuditLog;
use App\MailServer\Models\Domain;
use App\Models\DomainDkim;
use App\Models\DomainDnsCheck;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * A domain's DNS health (spec decisions 7 to 10): whether MX, SPF, DKIM, DMARC and PTR are set up for
 * this server, plus MTA-STS and autoconfig as information. Each check answers
 * `{status: ok|warn|fail|info, found, expected?, hint_key, publish?}`; `publish` is the record to add
 * (`{name, type, value, zone}`: `value` is the text to copy, `zone` the zone-file line, its TXT split
 * into 255-byte strings). The SPA translates `hint_key` (HINT_KEYS).
 *
 * Names are compared lowercased and without a trailing dot; TXT strings are joined before reading. A
 * lookup that fails (SERVFAIL, a timeout) fails its check with a `<check>.lookup_failed` hint, never an
 * exception, and the answers of one run are memoised, so each name is asked once.
 *
 * Checks run in a queued job (CheckDomainDns): every 6 h for every domain (routes/console.php) and on
 * demand (recheck(), audited). The latest result of each domain is stored in `domain_dns_checks`.
 *
 * @phpstan-type Publish array{name: string, type: string, value: string, zone: string}
 * @phpstan-type Check array{status: 'ok'|'warn'|'fail'|'info', found: list<string>, expected?: string, hint_key: string, publish?: Publish, selectors?: list<array{selector: string, state: string, published: bool, publish: Publish}>}
 * @phpstan-type Records list<array{value: string, pri?: int, entries?: list<string>}>
 */
class DnsHealth
{
    /** Every `hint_key` a check can give; the SPA has a sentence for each. */
    public const HINT_KEYS = [
        'mx.ok', 'mx.missing', 'mx.null_mx', 'mx.other_host', 'mx.lookup_failed',
        'spf.ok', 'spf.missing', 'spf.multiple', 'spf.pass_all', 'spf.include_only', 'spf.soft_no_host', 'spf.host_missing', 'spf.lookup_failed',
        'dkim.ok', 'dkim.missing', 'dkim.mismatch', 'dkim.no_key', 'dkim.pending_unpublished', 'dkim.pending_published', 'dkim.lookup_failed',
        'dmarc.ok', 'dmarc.missing', 'dmarc.multiple', 'dmarc.no_policy', 'dmarc.lookup_failed',
        'ptr.ok', 'ptr.missing', 'ptr.mismatch', 'ptr.not_forward_confirmed', 'ptr.no_addresses', 'ptr.lookup_failed',
        'mta_sts.found', 'mta_sts.missing', 'mta_sts.lookup_failed',
        'autoconfig.found', 'autoconfig.missing', 'autoconfig.lookup_failed',
    ];

    /** The record suggested when a domain has no SPF (spec "Records to publish"). */
    private const SPF_SUGGESTION = 'v=spf1 mx -all';

    /** How long a queued check shows as `checking`; the job clears it sooner when it ends. */
    private const QUEUED_SECONDS = 600;

    /** @var array<string, Records|null> "name TYPE" => records, null when the lookup failed */
    private array $memo = [];

    /** hrtime() after which a run asks nothing more (its lookups count as failed) */
    private int|float $deadline = 0;

    public function __construct(
        private readonly DnsResolver $resolver,
        private readonly AuditLog $audit,
        private readonly Cache $cache,
    ) {}

    /**
     * Runs every check against DNS now. Stores nothing (run() does).
     *
     * @return array{checked_at: string, checks: array{mx: Check, spf: Check, dkim: Check, dmarc: Check, ptr: Check, mta_sts: Check, autoconfig: Check}}
     */
    public function check(Domain $domain): array
    {
        $this->memo = [];
        $this->deadline = hrtime(true) + config()->integer('emailsquire.dns.budget') * 1_000_000_000;
        try {
            $name = self::name($domain->name);
            $host = self::name(config()->string('emailsquire.mail_host'));
            $addresses = $this->serverAddresses($host);
            $mx = $this->lookup($name, 'MX');

            return [
                'checked_at' => now()->toIso8601String(),
                'checks' => [
                    'mx' => $this->mx($name, $host, $mx),
                    'spf' => $this->spf($name, $host, $mx, $addresses ?? []),
                    'dkim' => $this->dkim($domain, $name),
                    'dmarc' => $this->dmarc($name),
                    'ptr' => $this->ptr($host, $addresses),
                    'mta_sts' => $this->mtaSts($name),
                    'autoconfig' => $this->autoconfig($name),
                ],
            ];
        } finally {
            $this->memo = [];
        }
    }

    /**
     * Checks the domain and stores the result as its latest.
     *
     * @return array{checked_at: string, checks: array<string, Check>}
     */
    public function run(Domain $domain): array
    {
        $result = $this->check($domain);
        DomainDnsCheck::query()->upsert([[
            'domain_id' => $domain->id,
            'domain_name' => $domain->name,
            'checked_at' => now(),
            'results' => json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]], ['domain_id'], ['domain_name', 'checked_at', 'results', 'updated_at']);

        return $result;
    }

    /**
     * A queued check (CheckDomainDns): runs it unless the domain was deleted, or its id now carries
     * another name, since it was queued.
     */
    public function runQueued(int $domainId, string $domainName): bool
    {
        $domain = Domain::query()->find($domainId);
        if (! $domain instanceof Domain || strtolower($domain->name) !== strtolower($domainName)) {
            return false;
        }
        $this->run($domain);

        return true;
    }

    /**
     * The domain's latest stored result; null when it has none (or the row was written for another
     * domain that had this id).
     *
     * @return array<string, mixed>|null {checked_at, checks} as check() returns it
     */
    public function latest(Domain $domain): ?array
    {
        $row = DomainDnsCheck::query()->find($domain->id);
        if ($row === null || strtolower($row->domain_name) !== strtolower($domain->name) || ! is_array($row->results['checks'] ?? null)) {
            return null;
        }

        return $row->results;
    }

    /**
     * Whether the latest stored check saw $selector's record published as the console knows it: what
     * DKIM activation needs before rspamd may sign with that key (Review Focus 2). False without a
     * stored check, or when the check ran before the key existed.
     */
    public function selectorPublished(Domain $domain, string $selector): bool
    {
        $checks = $this->latest($domain)['checks'] ?? null;
        $dkim = is_array($checks) ? ($checks['dkim'] ?? null) : null;
        $selectors = is_array($dkim) ? ($dkim['selectors'] ?? null) : null;
        if (! is_array($selectors)) {
            return false;
        }
        foreach ($selectors as $entry) {
            if (is_array($entry) && ($entry['selector'] ?? null) === $selector && ($entry['published'] ?? null) === true) {
                return true;
            }
        }

        return false;
    }

    /** Queues a check of the domain; `checking` shows until it has run. */
    public function queue(Domain $domain): void
    {
        $this->cache->put(self::queuedKey($domain->id), true, self::QUEUED_SECONDS);
        CheckDomainDns::dispatch($domain->id, $domain->name);
    }

    /** A check asked for on demand: audited as `domain.dns_checked`, then queued. */
    public function recheck(Domain $domain): void
    {
        DB::transaction(fn () => $this->audit->record('domain.dns_checked', 'domain', $domain->name, $domain->name));
        $this->queue($domain);
    }

    /** The scheduler's run: a queued check for every domain. Not audited. */
    public function queueAll(): int
    {
        $count = 0;
        Domain::query()->orderBy('id')->each(function (Domain $domain) use (&$count) {
            $this->queue($domain);
            $count++;
        });

        return $count;
    }

    public function isQueued(Domain $domain): bool
    {
        return $this->cache->has(self::queuedKey($domain->id));
    }

    public function forgetQueued(int $domainId): void
    {
        $this->cache->forget(self::queuedKey($domainId));
    }

    /**
     * @param  Records|null  $mx
     * @return Check
     */
    private function mx(string $domain, string $host, ?array $mx): array
    {
        $publish = self::publish($domain, 'MX', "10 {$host}.");
        if ($mx === null) {
            return self::result('fail', 'mx.lookup_failed', [], $host, $publish);
        }
        usort($mx, fn (array $a, array $b) => [$a['pri'] ?? 0, self::name($a['value'])] <=> [$b['pri'] ?? 0, self::name($b['value'])]);
        $targets = array_map(fn (array $r) => self::name($r['value']), $mx);
        $found = array_map(fn (array $r, string $target) => ($r['pri'] ?? 0).' '.($target === '' ? '.' : $target), $mx, $targets);

        return match (true) {
            $mx === [] => self::result('fail', 'mx.missing', [], $host, $publish),
            in_array($host, $targets, true) => self::result('ok', 'mx.ok', $found, $host),
            $targets === [''] => self::result('fail', 'mx.null_mx', $found, $host, $publish),
            default => self::result('fail', 'mx.other_host', $found, $host, $publish),
        };
    }

    /**
     * Spec decision 8: green when the domain's one `v=spf1` record passes this server through `mx`
     * (with MX to mail_host), `a:<mail_host>` or an `ip4:`/`ip6:` network covering a server address;
     * amber when it relies on `include:`/`redirect=` (not expanded), passes everyone (`+all`) or ends
     * soft (`~all`, `?all`, no `all`) without such a term; red with none, two, or a hard `-all` without.
     *
     * @param  Records|null  $mx
     * @param  list<string>  $addresses
     * @return Check
     */
    private function spf(string $domain, string $host, ?array $mx, array $addresses): array
    {
        $txt = $this->lookup($domain, 'TXT');
        if ($txt === null) {
            return self::result('fail', 'spf.lookup_failed');
        }
        $records = array_values(array_filter(array_map(self::text(...), $txt), fn (string $t) => preg_match('/^v=spf1(\s|$)/i', $t) === 1));
        if ($records === []) {
            return self::result('fail', 'spf.missing', [], null, self::publish($domain, 'TXT', self::SPF_SUGGESTION));
        }
        if (count($records) > 1) {
            return self::result('fail', 'spf.multiple', $records);
        }

        $record = $records[0];
        $terms = preg_split('/\s+/', trim($record), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        array_shift($terms);
        $covered = $unexpanded = $bareMx = false;
        $all = null;
        foreach ($terms as $term) {
            if (preg_match('/^redirect=/i', $term) === 1) {
                $unexpanded = true;

                continue;
            }
            if (preg_match('/^[a-z][a-z0-9_.-]*=/i', $term) === 1) {
                continue; // exp= and unknown modifiers
            }
            $qualifier = in_array($term[0], ['+', '-', '~', '?'], true) ? $term[0] : '+';
            $parts = explode(':', strtolower(ltrim($term, '+-~?')), 2);
            $kind = explode('/', $parts[0], 2)[0];
            $arg = $parts[1] ?? null;
            if ($kind === 'all') {
                $all = $qualifier;

                break; // RFC 7208: nothing after `all` is evaluated
            }
            if ($qualifier !== '+') {
                continue;
            }
            $bareMx = $bareMx || ($kind === 'mx' && $arg === null);
            $covered = $covered || match ($kind) {
                'mx' => $this->mxReaches($arg === null ? $domain : self::name(explode('/', $arg, 2)[0]), $domain, $mx, $host),
                'a' => ($arg === null ? $domain : self::name(explode('/', $arg, 2)[0])) === $host,
                'ip4', 'ip6' => $arg !== null && array_filter($addresses, fn (string $ip) => Cidr::contains($arg, $ip)) !== [],
                default => false,
            };
            $unexpanded = $unexpanded || $kind === 'include';
        }

        if ($covered) {
            return self::result('ok', 'spf.ok', [$record]);
        }
        // Adding `mx` lets this server send; a bare `mx` that is already there needs the MX fixed instead.
        $publish = $bareMx ? null : self::publish($domain, 'TXT', implode(' ', ['v=spf1', 'mx', ...$terms]));

        return match (true) {
            $all === '+' => self::result('warn', 'spf.pass_all', [$record]),
            $unexpanded => self::result('warn', 'spf.include_only', [$record], null, $publish),
            $all !== '-' => self::result('warn', 'spf.soft_no_host', [$record], null, $publish),
            default => self::result('fail', 'spf.host_missing', [$record], null, $publish),
        };
    }

    /** @param  Records|null  $domainMx */
    private function mxReaches(string $target, string $domain, ?array $domainMx, string $host): bool
    {
        $records = $target === $domain ? $domainMx : $this->lookup($target, 'MX');

        return $records !== null && in_array($host, array_map(fn (array $r) => self::name($r['value']), $records), true);
    }

    /**
     * The TXT at `<selector>._domainkey` of each pending and active key the console knows (domain_dkim)
     * against that key's record. The active key decides the status; a pending key's entry in
     * `selectors` says whether DNS already shows it (selectorPublished()).
     *
     * @return Check
     */
    private function dkim(Domain $domain, string $name): array
    {
        $keys = DomainDkim::query()
            ->where('domain_id', $domain->id)
            ->whereIn('state', ['pending', 'active'])
            ->orderByDesc('id')
            ->get()
            // A key imported without a record (one the helper could not read) has nothing to look for.
            ->filter(fn (DomainDkim $key) => strtolower($key->domain_name) === $name && $key->txt !== '')
            ->sortBy(fn (DomainDkim $key) => $key->state === 'active' ? 0 : 1) // the active key first, then the newest
            ->values();
        if ($keys->isEmpty()) {
            return self::result('warn', 'dkim.no_key', extra: ['selectors' => []]);
        }

        $found = $selectors = $seen = [];
        foreach ($keys as $key) {
            $at = "{$key->selector}._domainkey.{$name}";
            $txt = $this->lookup($at, 'TXT');
            $texts = array_map(self::text(...), $txt ?? []);
            foreach ($texts as $text) {
                $found[] = "{$key->selector}: {$text}";
            }
            $published = array_filter($texts, fn (string $t) => self::sameDkim($t, $key->txt)) !== [];
            $publish = self::publish($at, 'TXT', $key->txt);
            $selectors[] = ['selector' => $key->selector, 'state' => $key->state, 'published' => $published, 'publish' => $publish];
            $seen[$key->selector] = ['failed' => $txt === null, 'published' => $published, 'any' => $texts !== [], 'publish' => $publish];
        }

        $first = $keys->first();
        $s = $seen[$first->selector];
        $extra = ['selectors' => $selectors];
        if ($first->state === 'active') {
            return match (true) {
                $s['failed'] => self::result('fail', 'dkim.lookup_failed', $found, $first->txt, $s['publish'], $extra),
                $s['published'] => self::result('ok', 'dkim.ok', $found, $first->txt, null, $extra),
                $s['any'] => self::result('fail', 'dkim.mismatch', $found, $first->txt, $s['publish'], $extra),
                default => self::result('fail', 'dkim.missing', $found, $first->txt, $s['publish'], $extra),
            };
        }

        // No active key yet: the newest pending one is the next to sign.
        return match (true) {
            $s['failed'] => self::result('fail', 'dkim.lookup_failed', $found, $first->txt, $s['publish'], $extra),
            $s['published'] => self::result('warn', 'dkim.pending_published', $found, $first->txt, null, $extra),
            default => self::result('warn', 'dkim.pending_unpublished', $found, $first->txt, $s['publish'], $extra),
        };
    }

    /** @return Check */
    private function dmarc(string $domain): array
    {
        $at = "_dmarc.{$domain}";
        $txt = $this->lookup($at, 'TXT');
        if ($txt === null) {
            return self::result('fail', 'dmarc.lookup_failed');
        }
        $publish = self::publish($at, 'TXT', "v=DMARC1; p=none; rua=mailto:postmaster@{$domain}");
        $records = array_values(array_filter(array_map(self::text(...), $txt), fn (string $t) => preg_match('/^v\s*=\s*DMARC1\s*(;|$)/i', $t) === 1));

        return match (true) {
            $records === [] => self::result('fail', 'dmarc.missing', [], null, $publish),
            count($records) > 1 => self::result('fail', 'dmarc.multiple', $records),
            isset(self::tags($records[0])['p']) => self::result('ok', 'dmarc.ok', $records),
            default => self::result('warn', 'dmarc.no_policy', $records, null, $publish),
        };
    }

    /**
     * Forward-confirmed reverse DNS for each server address: its PTR names mail_host, and mail_host's
     * A or AAAA records include it.
     *
     * @param  list<string>|null  $addresses
     * @return Check
     */
    private function ptr(string $host, ?array $addresses): array
    {
        if ($addresses === null) {
            return self::result('fail', 'ptr.lookup_failed', [], $host);
        }
        if ($addresses === []) {
            return self::result('fail', 'ptr.no_addresses', [], $host);
        }

        $forward = $this->hostAddresses($host);
        $found = $problems = [];
        foreach ($addresses as $ip) {
            $arpa = self::reverseName($ip);
            $ptr = $this->lookup($arpa, 'PTR');
            if ($ptr === null) {
                $problems['ptr.lookup_failed'] ??= null;

                continue;
            }
            $targets = array_values(array_unique(array_map(fn (array $r) => self::name($r['value']), $ptr)));
            foreach ($targets as $target) {
                $found[] = "{$ip} → {$target}";
            }
            if (! in_array($host, $targets, true)) {
                $problems[$targets === [] ? 'ptr.missing' : 'ptr.mismatch'] ??= self::publish($arpa, 'PTR', "{$host}.");
            } elseif ($forward === null) {
                $problems['ptr.lookup_failed'] ??= null;
            } elseif (! in_array($ip, $forward, true)) {
                $problems['ptr.not_forward_confirmed'] ??= self::publish($host, str_contains($ip, ':') ? 'AAAA' : 'A', $ip);
            }
        }

        foreach (['ptr.lookup_failed', 'ptr.missing', 'ptr.mismatch', 'ptr.not_forward_confirmed'] as $hint) {
            if (array_key_exists($hint, $problems)) {
                return self::result('fail', $hint, $found, $host, $problems[$hint]);
            }
        }

        return self::result('ok', 'ptr.ok', $found, $host);
    }

    /** @return Check */
    private function mtaSts(string $domain): array
    {
        $txt = $this->lookup("_mta-sts.{$domain}", 'TXT');
        if ($txt === null) {
            return self::result('info', 'mta_sts.lookup_failed');
        }
        $records = array_values(array_filter(array_map(self::text(...), $txt), fn (string $t) => preg_match('/^v=STSv1(\s*;|$)/i', $t) === 1));

        return $records === [] ? self::result('info', 'mta_sts.missing') : self::result('info', 'mta_sts.found', $records);
    }

    /** @return Check */
    private function autoconfig(string $domain): array
    {
        $at = "autoconfig.{$domain}";
        $cname = $this->lookup($at, 'CNAME');
        if ($cname === null) {
            return self::result('info', 'autoconfig.lookup_failed');
        }
        if ($cname !== []) {
            return self::result('info', 'autoconfig.found', array_map(fn (array $r) => 'CNAME '.self::name($r['value']), $cname));
        }
        $a = $this->lookup($at, 'A');
        $aaaa = $this->lookup($at, 'AAAA');
        if ($a === null && $aaaa === null) {
            return self::result('info', 'autoconfig.lookup_failed');
        }
        $found = [
            ...array_map(fn (array $r) => 'A '.$r['value'], $a ?? []),
            ...array_map(fn (array $r) => 'AAAA '.$r['value'], $aaaa ?? []),
        ];

        return $found === [] ? self::result('info', 'autoconfig.missing') : self::result('info', 'autoconfig.found', $found);
    }

    /**
     * Spec decision 9: `emailsquire.server_ips`, or else mail_host's A and AAAA records. Null when
     * those lookups failed.
     *
     * @return list<string>|null
     */
    private function serverAddresses(string $host): ?array
    {
        $configured = config('emailsquire.server_ips');
        $addresses = [];
        foreach (is_array($configured) ? $configured : [] as $ip) {
            $normal = is_string($ip) ? self::ip($ip) : null;
            if ($normal !== null && ! in_array($normal, $addresses, true)) {
                $addresses[] = $normal;
            }
        }

        return $addresses !== [] ? $addresses : $this->hostAddresses($host);
    }

    /** @return list<string>|null mail_host's addresses; null when either lookup failed */
    private function hostAddresses(string $host): ?array
    {
        $a = $this->lookup($host, 'A');
        $aaaa = $this->lookup($host, 'AAAA');
        if ($a === null || $aaaa === null) {
            return null;
        }

        return array_values(array_unique(array_filter(array_map(fn (array $r) => self::ip($r['value']), [...$a, ...$aaaa]))));
    }

    /** @return Records|null */
    private function lookup(string $name, string $type): ?array
    {
        $key = "{$name} {$type}";
        if (! array_key_exists($key, $this->memo)) {
            try {
                // Past the run's budget, a slow resolver would keep the job past its timeout: ask nothing more.
                $this->memo[$key] = hrtime(true) >= $this->deadline ? null : $this->resolver->records($name, $type);
            } catch (DnsLookupFailed) {
                $this->memo[$key] = null;
            }
        }

        return $this->memo[$key];
    }

    /**
     * @param  'ok'|'warn'|'fail'|'info'  $status
     * @param  list<string>  $found
     * @param  Publish|null  $publish
     * @param  array{selectors?: list<array{selector: string, state: string, published: bool, publish: Publish}>}  $extra
     * @return Check
     */
    private static function result(string $status, string $hint, array $found = [], ?string $expected = null, ?array $publish = null, array $extra = []): array
    {
        $result = ['status' => $status, 'found' => $found];
        if ($expected !== null) {
            $result['expected'] = $expected;
        }
        $result['hint_key'] = $hint;
        if ($publish !== null) {
            $result['publish'] = $publish;
        }

        return $result + $extra;
    }

    /** @return Publish */
    private static function publish(string $name, string $type, string $value): array
    {
        $owner = "{$name}.";
        if ($type !== 'TXT') {
            return ['name' => $owner, 'type' => $type, 'value' => $value, 'zone' => "{$owner} IN {$type} {$value}"];
        }
        $strings = array_map(fn (string $chunk) => '"'.addcslashes($chunk, '"\\').'"', str_split($value, 255));
        $rdata = count($strings) === 1 ? $strings[0] : '( '.implode(' ', $strings).' )';

        return ['name' => $owner, 'type' => 'TXT', 'value' => $value, 'zone' => "{$owner} IN TXT {$rdata}"];
    }

    /** Whether a published DKIM record carries the same key as $expected: tags compared, whitespace ignored. */
    private static function sameDkim(string $published, string $expected): bool
    {
        $have = self::tags($published);
        $want = self::tags($expected);
        $key = $want['p'] ?? '';

        return $key !== ''
            && ($have['p'] ?? null) === $key
            && strtolower($have['k'] ?? 'rsa') === strtolower($want['k'] ?? 'rsa')
            && strtoupper($have['v'] ?? 'DKIM1') === 'DKIM1';
    }

    /** @return array<string, string> a `tag=value; …` record (DKIM, DMARC), tags lowercased, whitespace dropped from values */
    private static function tags(string $record): array
    {
        $tags = [];
        foreach (explode(';', $record) as $part) {
            $pair = explode('=', $part, 2);
            if (count($pair) === 2 && ($tag = strtolower(trim($pair[0]))) !== '') {
                $tags[$tag] ??= (string) preg_replace('/\s+/', '', $pair[1]);
            }
        }

        return $tags;
    }

    /** @param  array{value: string, entries?: list<string>}  $record */
    private static function text(array $record): string
    {
        return isset($record['entries']) ? implode('', $record['entries']) : $record['value'];
    }

    private static function queuedKey(int $domainId): string
    {
        return "emailsquire:dns-check-queued:{$domainId}";
    }

    private static function name(string $name): string
    {
        return strtolower(rtrim(trim($name), '.'));
    }

    private static function ip(string $ip): ?string
    {
        $packed = @inet_pton(trim($ip));
        $text = $packed === false ? false : inet_ntop($packed);

        return $text === false ? null : $text;
    }

    /** `10.2.0.192.in-addr.arpa` or the nibbles of an IPv6 address under `ip6.arpa`. */
    private static function reverseName(string $ip): string
    {
        $packed = (string) inet_pton($ip);
        if (strlen($packed) === 4) {
            return implode('.', array_reverse(explode('.', $ip))).'.in-addr.arpa';
        }

        return implode('.', str_split(strrev(bin2hex($packed)))).'.ip6.arpa';
    }
}
