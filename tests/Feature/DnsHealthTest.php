<?php

use App\Jobs\CheckDomainDns;
use App\MailServer\Dns\DnsHealth;
use App\MailServer\Dns\DnsResolver;
use App\MailServer\Dns\FakeDnsResolver;
use App\MailServer\Dns\SystemDnsResolver;
use App\MailServer\GuideSchema;
use App\MailServer\Models\Domain;
use App\Models\AuditEntry;
use App\Models\DomainDkim;
use App\Models\DomainDnsCheck;
use App\Models\DomainGrant;
use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\Support\GuideFixture;
use Tests\Support\StubDnsResolver;

// Phase 4, Task 3: each domain's DNS checked for MX, SPF, DKIM, DMARC and PTR (spec decisions 7 to 10),
// against the stub resolver (tests/Pest.php), never a real one.

const DNS_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0yCkY7BL2Z3hPxWvukB+xAdquwlRky8PJMoBIeN82eAIpk8pj1cMiUMhn1LBAWViJpmVIsfDbdR3I1wDqJLdHUa+3D6JMoR7xqIZmt9NiXyZFKbrH40Py8ZYXT1Rq5wOd0ppyCxwZ3qWVfjF87UX19WwPDC05iPVUAiS3MUeWXrRCgLh/sqKFH9o7K5H+QCeQMyC6TeGARvU96GL+CX+oAe6VQdt8x9miPsrFU8AIhe6rEszHlXPS7HoRFPgWc1nzeBaewu/gp18dO90fX0D4i0HSQZe3ZOHR/rHCNwo1LcFJ9V09plbQcu+rEBTyzpX1NSXrHACLa4rKr2oMtXzRQIDAQAB';
const DNS_OTHER_KEY = 'MIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEAotherkeyotherkeyotherkeyotherkeyotherkeyIDAQAB';

beforeEach(function () {
    config([
        'emailsquire.require_two_factor' => 'off',
        'emailsquire.mail_host' => 'mail.example.org',
        'emailsquire.server_ips' => ['192.0.2.10', '2001:db8::10'],
    ]);
    GuideFixture::load('trixie'); // example.org, id 10
    DB::table(GuideSchema::table('virtual_domains'))->insert(['id' => 11, 'name' => 'example.net']);
    $this->admin = User::factory()->create(['is_server_admin' => true, 'password_set_at' => now()]);
});

function dnsStub(): StubDnsResolver
{
    $stub = app(DnsResolver::class);
    assert($stub instanceof StubDnsResolver);

    return $stub;
}

function dnsDomain(string $name = 'example.org'): Domain
{
    $domain = Domain::named($name);
    assert($domain instanceof Domain);

    return $domain;
}

function dnsKey(string $selector, string $state, string $key = DNS_KEY, int $domainId = 10, string $domainName = 'example.org'): DomainDkim
{
    return DomainDkim::query()->forceCreate([
        'domain_id' => $domainId,
        'domain_name' => $domainName,
        'selector' => $selector,
        'txt' => "v=DKIM1; k=rsa; p={$key}",
        'state' => $state,
    ]);
}

/** A correctly set-up example.org: every check is green. */
function dnsGreen(): StubDnsResolver
{
    dnsKey('es20261001', 'active');

    return dnsStub()
        ->set('example.org', 'MX', ['10 mail.example.org'])
        ->set('example.org', 'TXT', ['google-site-verification=abc', 'v=spf1 mx -all'])
        // DKIM records are longer than one 255-byte string: the zone splits them, the check joins them.
        ->set('es20261001._domainkey.example.org', 'TXT', [['v=DKIM1; k=rsa; ', 'p='.substr(DNS_KEY, 0, 200), substr(DNS_KEY, 200)]])
        ->set('_dmarc.example.org', 'TXT', ['v=DMARC1; p=none; rua=mailto:postmaster@example.org'])
        ->set('mail.example.org', 'A', ['192.0.2.10'])
        ->set('mail.example.org', 'AAAA', ['2001:db8::10'])
        ->set('10.2.0.192.in-addr.arpa', 'PTR', ['mail.example.org'])
        ->set('0.1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa', 'PTR', ['mail.example.org']);
}

/** @return array<string, mixed> */
function dnsCheck(string $check, string $domain = 'example.org'): array
{
    return app(DnsHealth::class)->check(dnsDomain($domain))['checks'][$check];
}

function dnsUser(string $role, string $domain = 'example.org', int $id = 10): User
{
    $user = User::factory()->create(['password_set_at' => now()]);
    DomainGrant::query()->forceCreate(['user_id' => $user->id, 'domain_id' => $id, 'domain_name' => $domain, 'role' => $role]);

    return $user;
}

describe('the checks', function () {
    it('finds a correctly set-up domain green', function () {
        dnsGreen();
        $result = app(DnsHealth::class)->check(dnsDomain());

        expect(array_keys($result['checks']))->toBe(['mx', 'spf', 'dkim', 'dmarc', 'ptr', 'mta_sts', 'autoconfig'])
            ->and($result['checked_at'])->toBeString();
        foreach (['mx', 'spf', 'dkim', 'dmarc', 'ptr'] as $check) {
            expect($result['checks'][$check]['status'])->toBe('ok', $check)
                ->and($result['checks'][$check]['hint_key'])->toBe("{$check}.ok")
                ->and($result['checks'][$check])->not->toHaveKey('publish');
        }
        expect($result['checks']['mx']['found'])->toBe(['10 mail.example.org'])
            ->and($result['checks']['spf']['found'])->toBe(['v=spf1 mx -all'])
            ->and($result['checks']['dmarc']['found'])->toBe(['v=DMARC1; p=none; rua=mailto:postmaster@example.org'])
            ->and($result['checks']['ptr']['found'])->toBe(['192.0.2.10 → mail.example.org', '2001:db8::10 → mail.example.org'])
            ->and($result['checks']['mta_sts']['status'])->toBe('info')
            ->and($result['checks']['autoconfig']['status'])->toBe('info');
    });

    it('fails a missing MX and gives the record to publish', function () {
        $mx = dnsCheck('mx');

        expect($mx['status'])->toBe('fail')
            ->and($mx['hint_key'])->toBe('mx.missing')
            ->and($mx['found'])->toBe([])
            ->and($mx['expected'])->toBe('mail.example.org')
            ->and($mx['publish'])->toBe(['name' => 'example.org.', 'type' => 'MX', 'value' => '10 mail.example.org.', 'zone' => 'example.org. IN MX 10 mail.example.org.']);
    });

    it('fails an MX that points at another host, and a null MX', function () {
        dnsStub()->set('example.org', 'MX', ['10 mx.elsewhere.net', '20 mx2.elsewhere.net']);
        expect(dnsCheck('mx'))->toMatchArray(['status' => 'fail', 'hint_key' => 'mx.other_host', 'found' => ['10 mx.elsewhere.net', '20 mx2.elsewhere.net']]);

        dnsStub()->set('example.org', 'MX', ['0 .']);
        expect(dnsCheck('mx'))->toMatchArray(['status' => 'fail', 'hint_key' => 'mx.null_mx']);
    });

    it('warns when the MX names mail_host but mail_host is an alias (CNAME)', function () {
        dnsStub()->set('example.org', 'MX', ['10 mail.example.org'])->set('mail.example.org', 'CNAME', ['host.provider.net.']);
        $mx = dnsCheck('mx');

        expect($mx)->toMatchArray(['status' => 'warn', 'hint_key' => 'mx.cname', 'found' => ['10 mail.example.org']])
            ->and($mx)->not->toHaveKey('publish');
    });

    it('fails an MX that names an alias of mail_host: an MX must name the host itself', function () {
        dnsStub()->set('example.org', 'MX', ['10 mx.example.org'])->set('mx.example.org', 'CNAME', ['mail.example.org.']);

        expect(dnsCheck('mx'))->toMatchArray(['status' => 'fail', 'hint_key' => 'mx.other_host']);
    });

    it('accepts this host among several MX, in upper case and with a trailing dot', function () {
        dnsStub()->set('example.org', 'MX', ['20 backup.elsewhere.net.', '10 MAIL.Example.ORG.']);

        expect(dnsCheck('mx'))->toMatchArray(['status' => 'ok', 'found' => ['10 mail.example.org', '20 backup.elsewhere.net']]);
    });

    it('applies the SPF rules of spec decision 8', function (array|string $txt, string $status, string $hint) {
        dnsStub()
            ->set('example.org', 'MX', ['10 mail.example.org'])
            ->set('elsewhere.net', 'MX', ['10 mx.elsewhere.net'])
            ->set('example.org', 'TXT', is_array($txt) ? [$txt] : [$txt]);

        expect(dnsCheck('spf'))->toMatchArray(['status' => $status, 'hint_key' => $hint]);
    })->with([
        'mx with MX to this host' => ['v=spf1 mx -all', 'ok', 'spf.ok'],
        'mx of another domain' => ['v=spf1 mx:elsewhere.net -all', 'fail', 'spf.host_missing'],
        'a:host' => ['v=spf1 a:mail.example.org -all', 'ok', 'spf.ok'],
        'a:host in upper case with a dot' => ['v=spf1 a:MAIL.Example.org. -all', 'ok', 'spf.ok'],
        'ip4 covering' => ['v=spf1 ip4:192.0.2.0/24 -all', 'ok', 'spf.ok'],
        'ip4 exact' => ['v=spf1 ip4:192.0.2.10 -all', 'ok', 'spf.ok'],
        'ip4 elsewhere' => ['v=spf1 ip4:198.51.100.0/24 -all', 'fail', 'spf.host_missing'],
        'ip6 covering' => ['v=spf1 ip6:2001:db8::/32 -all', 'ok', 'spf.ok'],
        'ip6 elsewhere' => ['v=spf1 ip6:2001:db9::/32 -all', 'fail', 'spf.host_missing'],
        'a failing qualifier does not count' => ['v=spf1 -mx -all', 'fail', 'spf.host_missing'],
        'include only' => ['v=spf1 include:_spf.elsewhere.net -all', 'warn', 'spf.include_only'],
        'redirect' => ['v=spf1 redirect=_spf.elsewhere.net', 'warn', 'spf.include_only'],
        'softfail without a term' => ['v=spf1 ~all', 'warn', 'spf.soft_no_host'],
        'neutral without a term' => ['v=spf1 ?all', 'warn', 'spf.soft_no_host'],
        'no all and no term' => ['v=spf1 a:other.example.net', 'warn', 'spf.soft_no_host'],
        'pass all' => ['v=spf1 +all', 'warn', 'spf.pass_all'],
        'pass all beats a covering term' => ['v=spf1 mx +all', 'warn', 'spf.pass_all'],
        'ip4 network of length 0' => ['v=spf1 +ip4:0.0.0.0/0 -all', 'warn', 'spf.pass_all'],
        'ip6 network of length 0' => ['v=spf1 ip6:::/0 -all', 'warn', 'spf.pass_all'],
        'a failing open network is no pass all' => ['v=spf1 -ip4:0.0.0.0/0 mx -all', 'ok', 'spf.ok'],
        'redirect is ignored beside all' => ['v=spf1 redirect=_spf.elsewhere.net ~all', 'warn', 'spf.soft_no_host'],
        'redirect beside a hard all' => ['v=spf1 redirect=_spf.elsewhere.net -all', 'fail', 'spf.host_missing'],
        'an invalid byte is only a character' => ["v=spf1 \xff mx -all", 'ok', 'spf.ok'],
        'upper case' => ['V=SPF1 MX -ALL', 'ok', 'spf.ok'],
        'chunked' => [['v=spf1 ip4:192.0.2.0/24 ', '-all'], 'ok', 'spf.ok'],
        'covering beats softfail' => ['v=spf1 mx include:_spf.elsewhere.net ~all', 'ok', 'spf.ok'],
    ]);

    it('fails no SPF and suggests v=spf1 mx -all', function () {
        dnsStub()->set('example.org', 'TXT', ['google-site-verification=abc', 'v=spf10 nonsense']);
        $spf = dnsCheck('spf');

        expect($spf)->toMatchArray(['status' => 'fail', 'hint_key' => 'spf.missing', 'found' => []])
            ->and($spf['publish'])->toMatchArray(['name' => 'example.org.', 'type' => 'TXT', 'value' => 'v=spf1 mx -all']);
    });

    it('fails two SPF records (RFC 7208) without guessing a merge', function () {
        dnsStub()->set('example.org', 'TXT', ['v=spf1 mx -all', 'v=spf1 include:_spf.elsewhere.net ~all']);
        $spf = dnsCheck('spf');

        expect($spf)->toMatchArray(['status' => 'fail', 'hint_key' => 'spf.multiple'])
            ->and($spf['found'])->toHaveCount(2)
            ->and($spf)->not->toHaveKey('publish');
    });

    it('suggests adding mx to an SPF record that misses this host, keeping its other terms', function () {
        dnsStub()->set('example.org', 'TXT', ['v=spf1 include:_spf.elsewhere.net ~all']);

        expect(dnsCheck('spf')['publish']['value'])->toBe('v=spf1 mx include:_spf.elsewhere.net ~all');
    });

    it('takes the server addresses from mail_host when server_ips is empty', function () {
        config(['emailsquire.server_ips' => []]);
        dnsStub()
            ->set('mail.example.org', 'A', ['203.0.113.7'])
            ->set('example.org', 'TXT', ['v=spf1 ip4:203.0.113.0/28 -all']);

        expect(dnsCheck('spf')['status'])->toBe('ok');
    });

    it('finds the active DKIM key published, chunked and with odd whitespace', function () {
        dnsKey('es20261001', 'active');
        dnsStub()->set('es20261001._domainkey.example.org', 'TXT', [['v=DKIM1;  k=rsa;', ' p='.substr(DNS_KEY, 0, 100), substr(DNS_KEY, 100)]]);
        $dkim = dnsCheck('dkim');

        expect($dkim)->toMatchArray(['status' => 'ok', 'hint_key' => 'dkim.ok', 'expected' => 'v=DKIM1; k=rsa; p='.DNS_KEY])
            ->and($dkim['selectors'])->toBe([[
                'selector' => 'es20261001',
                'state' => 'active',
                'published' => true,
                'p_sha256' => hash('sha256', DNS_KEY),
                'publish' => [
                    'name' => 'es20261001._domainkey.example.org.',
                    'type' => 'TXT',
                    'value' => 'v=DKIM1; k=rsa; p='.DNS_KEY,
                    'zone' => 'es20261001._domainkey.example.org. IN TXT ( "'.substr('v=DKIM1; k=rsa; p='.DNS_KEY, 0, 255).'" "'.substr('v=DKIM1; k=rsa; p='.DNS_KEY, 255).'" )',
                ],
            ]]);
    });

    it('fails an active DKIM key whose record is missing or different', function () {
        dnsKey('es20261001', 'active');
        $missing = dnsCheck('dkim');
        expect($missing)->toMatchArray(['status' => 'fail', 'hint_key' => 'dkim.missing'])
            ->and($missing['publish']['value'])->toBe('v=DKIM1; k=rsa; p='.DNS_KEY);

        dnsStub()->set('es20261001._domainkey.example.org', 'TXT', ['v=DKIM1; k=rsa; p='.DNS_OTHER_KEY]);
        $mismatch = dnsCheck('dkim');
        expect($mismatch)->toMatchArray(['status' => 'fail', 'hint_key' => 'dkim.mismatch', 'found' => ['es20261001: v=DKIM1; k=rsa; p='.DNS_OTHER_KEY]])
            ->and($mismatch['publish']['value'])->toBe('v=DKIM1; k=rsa; p='.DNS_KEY);
    });

    it('sees a pending key once its record is published, which lets it be activated', function () {
        dnsGreen();
        $pending = dnsKey('es20261011', 'pending', DNS_OTHER_KEY);
        $active = DomainDkim::query()->where('selector', 'es20261001')->sole();
        $health = app(DnsHealth::class);

        $health->run(dnsDomain());
        $dkim = $health->latest(dnsDomain())['checks']['dkim'];
        expect($dkim['status'])->toBe('ok')
            ->and(collect($dkim['selectors'])->firstWhere('selector', 'es20261011'))->toMatchArray(['state' => 'pending', 'published' => false])
            ->and(collect($dkim['selectors'])->firstWhere('selector', 'es20261011')['publish']['value'])->toBe('v=DKIM1; k=rsa; p='.DNS_OTHER_KEY)
            ->and($health->selectorPublished(dnsDomain(), $pending))->toBeFalse()
            ->and($health->selectorPublished(dnsDomain(), $active))->toBeTrue();

        dnsStub()->set('es20261011._domainkey.example.org', 'TXT', ['v=DKIM1; k=rsa; p='.DNS_OTHER_KEY]);
        $health->run(dnsDomain());
        $unknown = new DomainDkim;
        $unknown->forceFill(['domain_id' => 10, 'domain_name' => 'example.org', 'selector' => 'es20990101', 'txt' => 'v=DKIM1; k=rsa; p='.DNS_KEY]);
        expect($health->selectorPublished(dnsDomain(), $pending))->toBeTrue()
            ->and($health->selectorPublished(dnsDomain(), $unknown))->toBeFalse()
            ->and($health->selectorPublished(dnsDomain('example.net'), $pending))->toBeFalse();
    });

    it('ties the proof to the key itself: a reused selector or a key changed after the check is not published', function () {
        dnsGreen();
        $pending = dnsKey('es20261011', 'pending', DNS_OTHER_KEY);
        dnsStub()->set('es20261011._domainkey.example.org', 'TXT', ['v=DKIM1; k=rsa; p='.DNS_OTHER_KEY]);
        $health = app(DnsHealth::class);
        $health->run(dnsDomain());
        expect($health->selectorPublished(dnsDomain(), $pending))->toBeTrue();

        // The same selector now names another key: the stored proof is about the old one.
        $pending->forceFill(['txt' => 'v=DKIM1; k=rsa; p='.DNS_KEY])->save();
        expect($health->selectorPublished(dnsDomain(), $pending->fresh()))->toBeFalse();

        // The row changed after the check, even with the same record: check again first.
        $pending->forceFill(['txt' => 'v=DKIM1; k=rsa; p='.DNS_OTHER_KEY])->save();
        DB::table('domain_dkim')->where('id', $pending->id)->update(['updated_at' => now()->addMinute()]);
        expect($health->selectorPublished(dnsDomain(), $pending->fresh()))->toBeFalse();
    });

    it('warns about a pending key alone, with its record to publish until DNS shows it', function () {
        dnsKey('es20261011', 'pending');
        $unpublished = dnsCheck('dkim');
        expect($unpublished)->toMatchArray(['status' => 'warn', 'hint_key' => 'dkim.pending_unpublished'])
            ->and($unpublished['publish']['name'])->toBe('es20261011._domainkey.example.org.');

        dnsStub()->set('es20261011._domainkey.example.org', 'TXT', ['v=DKIM1; k=rsa; p='.DNS_KEY]);
        $published = dnsCheck('dkim');
        expect($published)->toMatchArray(['status' => 'warn', 'hint_key' => 'dkim.pending_published'])
            ->and($published)->not->toHaveKey('publish');
    });

    it('warns when the console knows no DKIM key, and ignores retired keys and other domains', function () {
        dnsKey('es20250101', 'retired');
        dnsKey('es20261001', 'active', domainId: 11, domainName: 'example.net');
        // A row whose domain id now carries another name grants nothing (a reused id).
        dnsKey('es20261002', 'active', domainId: 10, domainName: 'old.example.org');

        expect(dnsCheck('dkim'))->toMatchArray(['status' => 'warn', 'hint_key' => 'dkim.no_key', 'found' => [], 'selectors' => []]);
    });

    it('checks DMARC', function () {
        $missing = dnsCheck('dmarc');
        expect($missing)->toMatchArray(['status' => 'fail', 'hint_key' => 'dmarc.missing'])
            ->and($missing['publish'])->toMatchArray(['name' => '_dmarc.example.org.', 'type' => 'TXT', 'value' => 'v=DMARC1; p=none; rua=mailto:postmaster@example.org']);

        dnsStub()->set('_dmarc.example.org', 'TXT', ['v=DMARC1; p=reject']);
        expect(dnsCheck('dmarc'))->toMatchArray(['status' => 'ok', 'hint_key' => 'dmarc.ok']);

        dnsStub()->set('_dmarc.example.org', 'TXT', ['V=dmarc1;P=quarantine']);
        expect(dnsCheck('dmarc')['status'])->toBe('ok');

        dnsStub()->set('_dmarc.example.org', 'TXT', ['v=DMARC1; p=rejected']);
        expect(dnsCheck('dmarc'))->toMatchArray(['status' => 'warn', 'hint_key' => 'dmarc.invalid_policy'])
            ->and(dnsCheck('dmarc')['publish']['value'])->toBe('v=DMARC1; p=none; rua=mailto:postmaster@example.org');

        dnsStub()->set('_dmarc.example.org', 'TXT', ['v=DMARC1; p=REJECT']);
        expect(dnsCheck('dmarc')['status'])->toBe('ok');

        dnsStub()->set('_dmarc.example.org', 'TXT', ['v=DMARC1; rua=mailto:x@example.org']);
        expect(dnsCheck('dmarc'))->toMatchArray(['status' => 'warn', 'hint_key' => 'dmarc.no_policy']);

        dnsStub()->set('_dmarc.example.org', 'TXT', ['v=DMARC1; p=none', 'v=DMARC1; p=reject']);
        expect(dnsCheck('dmarc'))->toMatchArray(['status' => 'fail', 'hint_key' => 'dmarc.multiple']);
    });

    it('checks PTR both ways: the address names mail_host, and mail_host resolves back to it', function () {
        dnsGreen();
        expect(dnsCheck('ptr'))->toMatchArray(['status' => 'ok', 'expected' => 'mail.example.org']);

        // PTR answers come back with a trailing dot and in any case.
        dnsStub()->set('10.2.0.192.in-addr.arpa', 'PTR', ['MAIL.example.org.']);
        expect(dnsCheck('ptr')['status'])->toBe('ok');

        dnsStub()->set('10.2.0.192.in-addr.arpa', 'PTR', ['host-10.provider.net']);
        $mismatch = dnsCheck('ptr');
        expect($mismatch)->toMatchArray(['status' => 'fail', 'hint_key' => 'ptr.mismatch'])
            ->and($mismatch['found'])->toContain('192.0.2.10 → host-10.provider.net')
            ->and($mismatch['publish'])->toMatchArray(['name' => '10.2.0.192.in-addr.arpa.', 'type' => 'PTR', 'value' => 'mail.example.org.']);

        dnsStub()->set('10.2.0.192.in-addr.arpa', 'PTR', []);
        expect(dnsCheck('ptr'))->toMatchArray(['status' => 'fail', 'hint_key' => 'ptr.missing']);

        // The PTR is right, but mail_host does not resolve back to the address.
        dnsStub()->set('10.2.0.192.in-addr.arpa', 'PTR', ['mail.example.org'])->set('mail.example.org', 'A', ['192.0.2.99']);
        $unconfirmed = dnsCheck('ptr');
        expect($unconfirmed)->toMatchArray(['status' => 'fail', 'hint_key' => 'ptr.not_forward_confirmed'])
            ->and($unconfirmed['publish'])->toMatchArray(['name' => 'mail.example.org.', 'type' => 'A', 'value' => '192.0.2.10']);
    });

    it('reads an IPv4-mapped IPv6 server address as IPv4', function () {
        config(['emailsquire.server_ips' => ['::ffff:192.0.2.10']]);
        dnsGreen()->set('example.org', 'TXT', ['v=spf1 ip4:192.0.2.0/24 -all']);

        expect(dnsCheck('ptr'))->toMatchArray(['status' => 'ok', 'found' => ['192.0.2.10 → mail.example.org']])
            ->and(dnsCheck('spf')['status'])->toBe('ok');
    });

    it('fails PTR when there is no server address to check', function () {
        config(['emailsquire.server_ips' => []]);

        expect(dnsCheck('ptr'))->toMatchArray(['status' => 'fail', 'hint_key' => 'ptr.no_addresses']);
    });

    it('ignores a server_ips entry that is not an address', function () {
        config(['emailsquire.server_ips' => ['not-an-ip', ' 192.0.2.10 ']]);
        dnsGreen();

        expect(dnsCheck('ptr'))->toMatchArray(['status' => 'ok', 'found' => ['192.0.2.10 → mail.example.org']]);
    });

    it('shows MTA-STS and autoconfig as information only', function () {
        expect(dnsCheck('mta_sts'))->toMatchArray(['status' => 'info', 'hint_key' => 'mta_sts.missing'])
            ->and(dnsCheck('autoconfig'))->toMatchArray(['status' => 'info', 'hint_key' => 'autoconfig.missing']);

        dnsStub()->set('_mta-sts.example.org', 'TXT', ['v=STSv1; id=20261011'])
            ->set('autoconfig.example.org', 'CNAME', ['mail.example.org.']);
        expect(dnsCheck('mta_sts'))->toMatchArray(['status' => 'info', 'hint_key' => 'mta_sts.found', 'found' => ['v=STSv1; id=20261011']])
            ->and(dnsCheck('autoconfig'))->toMatchArray(['status' => 'info', 'hint_key' => 'autoconfig.found', 'found' => ['CNAME mail.example.org']]);

        dnsStub()->set('autoconfig.example.org', 'CNAME', [])->set('autoconfig.example.org', 'A', ['192.0.2.10']);
        expect(dnsCheck('autoconfig'))->toMatchArray(['status' => 'info', 'found' => ['A 192.0.2.10']]);
    });

    it('turns a failing lookup (SERVFAIL, a timeout) into a failed check with a hint, never an exception', function () {
        dnsKey('es20261001', 'active');
        foreach ([['example.org', 'MX'], ['example.org', 'TXT'], ['es20261001._domainkey.example.org', 'TXT'], ['_dmarc.example.org', 'TXT'], ['10.2.0.192.in-addr.arpa', 'PTR'], ['_mta-sts.example.org', 'TXT'], ['autoconfig.example.org', 'CNAME']] as [$name, $type]) {
            dnsStub()->fail($name, $type);
        }
        $checks = app(DnsHealth::class)->check(dnsDomain())['checks'];

        foreach (['mx', 'spf', 'dkim', 'dmarc', 'ptr'] as $check) {
            expect($checks[$check])->toMatchArray(['status' => 'fail', 'hint_key' => "{$check}.lookup_failed"]);
        }
        expect($checks['mta_sts'])->toMatchArray(['status' => 'info', 'hint_key' => 'mta_sts.lookup_failed'])
            ->and($checks['autoconfig'])->toMatchArray(['status' => 'info', 'hint_key' => 'autoconfig.lookup_failed']);
    });

    it('answers NXDOMAIN (no records anywhere) with the records to publish', function () {
        dnsKey('es20261001', 'active');
        $checks = app(DnsHealth::class)->check(dnsDomain())['checks'];

        expect($checks['mx']['hint_key'])->toBe('mx.missing')
            ->and($checks['spf']['hint_key'])->toBe('spf.missing')
            ->and($checks['dkim']['hint_key'])->toBe('dkim.missing')
            ->and($checks['dmarc']['hint_key'])->toBe('dmarc.missing')
            ->and($checks['ptr']['hint_key'])->toBe('ptr.missing');
        foreach (['mx', 'spf', 'dkim', 'dmarc', 'ptr'] as $check) {
            expect($checks[$check]['status'])->toBe('fail')->and($checks[$check])->toHaveKey('publish');
        }
    });

    it('stops asking once a run has used its time budget, so a dead resolver cannot hold the job', function () {
        dnsGreen();
        config(['emailsquire.dns.budget' => 0]);
        $checks = app(DnsHealth::class)->check(dnsDomain())['checks'];

        expect(dnsStub()->queries)->toBe([])
            ->and($checks['mx']['hint_key'])->toBe('mx.lookup_failed')
            ->and($checks['dkim']['hint_key'])->toBe('dkim.lookup_failed');
    });

    it('stores an answer that is not UTF-8, on the job path and the first read alike', function () {
        dnsGreen()
            ->set('example.org', 'TXT', ["v=spf1 \xff mx -all"])
            ->set('_dmarc.example.org', 'TXT', ["v=DMARC1; p=none; rua=mailto:\xc3(@example.org"])
            ->set('10.2.0.192.in-addr.arpa', 'PTR', ["mail\xfe.example.org"]);

        (new CheckDomainDns(10, 'example.org'))->handle(app(DnsHealth::class));
        $stored = app(DnsHealth::class)->latest(dnsDomain())['checks'];
        expect($stored['spf']['status'])->toBe('ok')
            ->and(mb_check_encoding(json_encode($stored, JSON_THROW_ON_ERROR), 'UTF-8'))->toBeTrue()
            ->and($stored['ptr']['hint_key'])->toBe('ptr.mismatch');

        DomainDnsCheck::query()->delete();
        $this->actingAs($this->admin)->getJson('/api/v1/domains/example.org/dns')
            ->assertOk()->assertJsonPath('result.checks.spf.status', 'ok')->assertJsonPath('result.checks.dmarc.status', 'ok');
    });

    it('keeps at most 20 found entries of at most 1024 bytes each', function () {
        dnsStub()
            ->set('example.org', 'MX', array_map(fn (int $i) => "{$i} mx{$i}.elsewhere.net", range(1, 50)))
            ->set('example.org', 'TXT', array_map(fn (int $i) => 'v=spf1 '.str_repeat("ip4:198.51.100.{$i} ", 300).'-all', range(1, 30)))
            ->set('_dmarc.example.org', 'TXT', ['v=DMARC1; p=none; rua='.str_repeat('é', 2000)]);
        $checks = app(DnsHealth::class)->check(dnsDomain())['checks'];

        expect($checks['mx']['found'])->toHaveCount(20)
            ->and($checks['mx']['found'][18])->toBe('19 mx19.elsewhere.net')
            ->and($checks['mx']['found'][19])->toBe('…')
            ->and($checks['spf']['hint_key'])->toBe('spf.multiple')
            ->and($checks['spf']['found'])->toHaveCount(20);
        foreach ([...$checks['spf']['found'], ...$checks['dmarc']['found']] as $entry) {
            expect(strlen($entry))->toBeLessThanOrEqual(1024)
                ->and(mb_check_encoding($entry, 'UTF-8'))->toBeTrue();
        }
        expect($checks['dmarc']['found'][0])->toEndWith('é…')
            ->and($checks['spf']['found'][0])->toEndWith('…');

        // And the whole result stores.
        dnsStub()->set('example.org', 'TXT', []);
        expect(app(DnsHealth::class)->run(dnsDomain())['checks']['mx']['found'])->toHaveCount(20);
    });

    it('asks for each name once per run', function () {
        dnsGreen();
        app(DnsHealth::class)->check(dnsDomain());

        $queries = dnsStub()->queries;
        expect(count($queries))->toBe(count(array_unique($queries)));
    });

    it('lists every hint key it can give', function () {
        dnsGreen();
        $keys = DnsHealth::HINT_KEYS;
        foreach (app(DnsHealth::class)->check(dnsDomain())['checks'] as $check) {
            expect($keys)->toContain($check['hint_key']);
        }
        expect(count($keys))->toBe(count(array_unique($keys)));
    });
});

describe('the API', function () {
    it('queues the first check when there is no result yet, and answers checking', function () {
        Queue::fake();

        $this->actingAs($this->admin)->getJson('/api/v1/domains/example.org/dns')
            ->assertOk()->assertExactJson(['result' => null, 'checking' => true]);
        Queue::assertPushed(CheckDomainDns::class, fn (CheckDomainDns $job) => $job->domainId === 10 && $job->domainName === 'example.org');
        expect(AuditEntry::query()->count())->toBe(0);
    });

    it('answers the stored result once the check has run', function () {
        dnsGreen();
        // The suite's queue is sync: the first read queues the check, which runs before the answer.
        $response = $this->actingAs($this->admin)->getJson('/api/v1/domains/EXAMPLE.org/dns')->assertOk();

        expect($response->json('checking'))->toBeFalse()
            ->and($response->json('result.checks.mx.status'))->toBe('ok')
            ->and($response->json('result.checked_at'))->toBeString()
            ->and(DomainDnsCheck::query()->count())->toBe(1);

        // Reading again does not look anything up.
        $before = count(dnsStub()->queries);
        $this->getJson('/api/v1/domains/example.org/dns')->assertOk()->assertJsonPath('result.checks.spf.status', 'ok');
        expect(count(dnsStub()->queries))->toBe($before);
    });

    it('rechecks on demand for admins and managers, with a domain.dns_checked audit row', function () {
        dnsGreen();
        $this->actingAs($this->admin)->postJson('/api/v1/domains/example.org/dns/recheck')
            ->assertStatus(202)->assertJsonPath('result.checks.dkim.status', 'ok');

        $entry = AuditEntry::query()->where('action', 'domain.dns_checked')->sole();
        expect($entry->object_type)->toBe('domain')
            ->and($entry->object_key)->toBe('example.org')
            ->and($entry->domain_name)->toBe('example.org')
            ->and($entry->changes)->toBeNull();

        $this->actingAs(dnsUser('manager', 'example.net', 11))->postJson('/api/v1/domains/example.net/dns/recheck')->assertStatus(202);
        expect(AuditEntry::query()->where('action', 'domain.dns_checked')->count())->toBe(2);
    });

    it('lets any role read the checks and only admins and managers recheck', function () {
        $viewer = dnsUser('viewer');
        $this->actingAs($viewer)->getJson('/api/v1/domains/example.org/dns')->assertOk();
        $this->actingAs($viewer)->postJson('/api/v1/domains/example.org/dns/recheck')->assertForbidden()->assertJsonPath('code', 'forbidden');

        $outsider = dnsUser('manager', 'example.net', 11);
        $this->actingAs($outsider)->getJson('/api/v1/domains/example.org/dns')->assertNotFound()->assertJsonPath('code', 'not_found');
        $this->actingAs($outsider)->postJson('/api/v1/domains/example.org/dns/recheck')->assertNotFound();
        expect(AuditEntry::query()->where('action', 'domain.dns_checked')->count())->toBe(0);
    });

    it('allows one recheck a minute per domain', function () {
        $this->freezeTime();
        $this->actingAs($this->admin)->postJson('/api/v1/domains/example.org/dns/recheck')->assertStatus(202);

        $this->postJson('/api/v1/domains/example.org/dns/recheck')
            ->assertStatus(429)->assertJsonPath('code', 'too_many_requests')->assertHeader('Retry-After');
        // Per domain, not per user: a manager of the same domain waits too; another domain does not.
        $this->actingAs(dnsUser('manager'))->postJson('/api/v1/domains/example.org/dns/recheck')->assertStatus(429);
        $this->actingAs($this->admin)->postJson('/api/v1/domains/example.net/dns/recheck')->assertStatus(202);
        expect(AuditEntry::query()->where('action', 'domain.dns_checked')->count())->toBe(2);

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/domains/example.org/dns/recheck')->assertStatus(202);
    });

    it('does not count a refused recheck against the domain', function () {
        $this->freezeTime();
        $this->actingAs(dnsUser('viewer'))->postJson('/api/v1/domains/example.org/dns/recheck')->assertForbidden();
        $this->actingAs(dnsUser('manager', 'example.net', 11))->postJson('/api/v1/domains/example.org/dns/recheck')->assertNotFound();

        $this->actingAs($this->admin)->postJson('/api/v1/domains/example.org/dns/recheck')->assertStatus(202);
    });

    it('never answers 500 when every lookup fails', function () {
        dnsKey('es20261001', 'active');
        foreach (['example.org MX', 'example.org TXT', '_dmarc.example.org TXT', 'es20261001._domainkey.example.org TXT', 'mail.example.org A', 'mail.example.org AAAA', '10.2.0.192.in-addr.arpa PTR'] as $q) {
            dnsStub()->fail(...explode(' ', $q));
        }

        $this->actingAs($this->admin)->postJson('/api/v1/domains/example.org/dns/recheck')
            ->assertStatus(202)->assertJsonPath('result.checks.mx.hint_key', 'mx.lookup_failed');
    });
});

describe('the job and the scheduler', function () {
    it('dispatches a check for every domain every six hours, without audit rows', function () {
        $event = collect(app(Schedule::class)->events())->first(fn (Event $e) => $e->description === 'emailsquire:dns-checks');
        expect($event)->not->toBeNull()
            ->and($event->expression)->toBe('0 */6 * * *');

        Queue::fake();
        $event->run(app());
        Queue::assertPushed(CheckDomainDns::class, 2);
        Queue::assertPushed(CheckDomainDns::class, fn (CheckDomainDns $job) => $job->domainName === 'example.net' && $job->domainId === 11);
        expect(AuditEntry::query()->count())->toBe(0);
    });

    it('stores the result of a scheduled check without an audit row', function () {
        dnsGreen();
        (new CheckDomainDns(10, 'example.org'))->handle(app(DnsHealth::class));

        expect(app(DnsHealth::class)->latest(dnsDomain())['checks']['mx']['status'])->toBe('ok')
            ->and(AuditEntry::query()->count())->toBe(0);
    });

    it('gives the job time for the run budget plus one slow lookup, inside the queue\'s retry_after', function () {
        $job = new CheckDomainDns(10, 'example.org');

        // One lookup against a dead resolver can take about 30 s (glibc: 5 s × 2 attempts × 3 name servers).
        expect($job->timeout)->toBeGreaterThanOrEqual(config()->integer('emailsquire.dns.budget') + 30)
            ->and(config()->integer('queue.connections.database.retry_after'))->toBeGreaterThan($job->timeout)
            ->and($job->uniqueFor)->toBe(6 * 3600);
    });

    it('skips a domain deleted or renamed after the check was queued', function () {
        (new CheckDomainDns(10, 'renamed.org'))->handle(app(DnsHealth::class));
        (new CheckDomainDns(99, 'gone.org'))->handle(app(DnsHealth::class));

        expect(DomainDnsCheck::query()->count())->toBe(0);
    });

    it('stops answering checking once the queued check has run', function () {
        Queue::fake();
        $health = app(DnsHealth::class);
        $health->queue(dnsDomain());
        expect($health->isQueued(dnsDomain()))->toBeTrue();

        (new CheckDomainDns(10, 'example.org'))->handle($health);
        expect($health->isQueued(dnsDomain()))->toBeFalse();
    });

    it('does not show a stored result to a domain that reuses the id under another name', function () {
        app(DnsHealth::class)->run(dnsDomain());
        DB::table(GuideSchema::table('virtual_domains'))->where('id', 10)->update(['name' => 'reused.org']);

        expect(app(DnsHealth::class)->latest(dnsDomain('reused.org')))->toBeNull();
    });

    it('deletes the stored checks with the domain', function () {
        app(DnsHealth::class)->run(dnsDomain('example.net'));
        expect(DomainDnsCheck::query()->count())->toBe(1);

        confirmStepUp();
        $this->actingAs($this->admin)->deleteJson('/api/v1/domains/example.net', ['confirm_name' => 'example.net'])->assertNoContent();
        expect(DomainDnsCheck::query()->count())->toBe(0);
    });
});

describe('the e2e stand-in', function () {
    it('binds the system resolver everywhere but e2e, and the stand-in only in e2e', function () {
        $binding = function (string $env): DnsResolver {
            $previous = app()['env'];
            app()['env'] = $env;
            try {
                (new AppServiceProvider(app()))->register();
                app()->forgetInstance(DnsResolver::class);

                return app(DnsResolver::class);
            } finally {
                app()['env'] = $previous;
            }
        };

        expect($binding('production'))->toBeInstanceOf(SystemDnsResolver::class)
            ->and($binding('local'))->toBeInstanceOf(SystemDnsResolver::class)
            ->and($binding('testing'))->toBeInstanceOf(SystemDnsResolver::class)
            ->and($binding('e2e'))->toBeInstanceOf(FakeDnsResolver::class);
    });

    it('refuses to answer outside the e2e environment', function () {
        expect(fn () => (new FakeDnsResolver(base_path('tests/e2e/fixtures/dns.json')))->records('example.org', 'MX'))
            ->toThrow(LogicException::class);
    });

    it('serves the fixture in e2e: example.org green, example.net without DMARC and DKIM', function () {
        config(['emailsquire.mail_host' => 'mail.example.org', 'emailsquire.server_ips' => ['192.0.2.10', '2001:db8::10']]);
        // tests/e2e/seed.php gives example.org this active key, as published in the fixture.
        $chunks = json_decode((string) file_get_contents(base_path('tests/e2e/fixtures/dns.json')), true)['es20261001._domainkey.example.org']['TXT'][0];
        dnsKey('es20261001', 'active', substr(implode('', $chunks), strlen('v=DKIM1; k=rsa; p=')));
        dnsKey('es20261001', 'active', 'NETKEY', 11, 'example.net');

        $previous = app()['env'];
        app()['env'] = 'e2e';
        try {
            app()->instance(DnsResolver::class, new FakeDnsResolver(base_path('tests/e2e/fixtures/dns.json')));
            $org = app(DnsHealth::class)->check(dnsDomain())['checks'];
            $net = app(DnsHealth::class)->check(dnsDomain('example.net'))['checks'];
        } finally {
            app()['env'] = $previous;
        }

        foreach (['mx', 'spf', 'dkim', 'dmarc', 'ptr'] as $check) {
            expect($org[$check]['status'])->toBe('ok', "example.org {$check}");
        }
        expect($net['mx']['status'])->toBe('ok')
            ->and($net['spf']['status'])->toBe('ok')
            ->and($net['ptr']['status'])->toBe('ok')
            ->and($net['dmarc']['hint_key'])->toBe('dmarc.missing')
            ->and($net['dkim']['hint_key'])->toBe('dkim.missing');
    });
});
