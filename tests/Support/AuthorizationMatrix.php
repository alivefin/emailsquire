<?php

namespace Tests\Support;

use App\Auth\Mailbox\MailboxPrincipal;
use App\Doctor\Support\AliasMaps;
use App\MailServer\CatchAllSupport;
use App\MailServer\GuideSchema;
use App\Models\BlockedAddress;
use App\Models\DomainDkim;
use App\Models\DomainGrant;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The §6 role matrix as data, shared by MatrixTest (which runs it) and RouteAuthorizationTest (which
 * fails when a `can:` route is missing from it).
 *
 * The world: the guide holds a.org and b.org, each with the mailbox john@, the forwarding jack@ → john@
 * and the blocked address spam@. The server supports catch-alls (CatchAllSupport); a row with
 * `catch_all` also gets the catch-all @ → john@ on both domains (prepare()). The principals are a server admin, a manager and a viewer of a.org,
 * and a user with no grant. No principal has a grant on b.org. One more user, the target
 * (`{user}` in a uri), is a viewer of both domains, so the access routes have someone to act on.
 * On the Users screen's routes (`users/{user}...`), `{user}` is instead the domain's member: a user
 * per domain with a viewer grant on it and confirmed two-factor, so a route that deletes or changes
 * them succeeds on both domains. Each domain has a pending invitation from the admin (`{invitation}`
 * in a uri), and a pending DKIM key (`{selector}`).
 *
 * To add a route: add one row to routes(), keyed "METHOD uri" exactly as registered (without
 * `api/v1/`), with the body and query it needs to succeed for the admin and the expected status per
 * principal as [on a.org, on b.org]. The helpers below spell the common shapes. A row whose answer
 * changes when `emailsquire.managers_can_share` is on also gives `sharing`: the expectations then.
 */
final class AuthorizationMatrix
{
    public const PRINCIPALS = ['admin', 'manager', 'viewer', 'none'];

    /**
     * The mailbox column: john@a.org signed in to the portal (`mailbox` guard). Console routes accept only
     * `auth:web`, so it gets 401 everywhere, on its own domain too. MatrixTest signs it in with
     * actingAs(…, 'mailbox'), which also makes `mailbox` the default guard: a console route that used
     * the default guard instead of `auth:web` would let it through.
     */
    public const MAILBOX = 'mailbox';

    /** @var array{0: int, 1: int} */
    private const UNAUTHENTICATED = [401, 401];

    public const DOMAINS = ['a.org', 'b.org'];

    public const A_ID = 20;

    public const B_ID = 30;

    /** The user `{user}` stands for: a viewer of both domains, and not a principal. */
    public const TARGET_EMAIL = 'target@console.test';

    /** `{user}` on the Users screen's routes: the domain's member, member-{domain}@console.test. */
    public const MEMBER_EMAIL = 'member-%s@console.test';

    /** Who `{invitation}` invites: the pending invitation of the domain in the uri. */
    public const INVITED_EMAIL = 'invited@console.test';

    /** `{selector}`: a pending DKIM key of the domain in the uri. */
    public const SELECTOR = 'es20261001';

    /**
     * @return array<string, array{body?: array<string, mixed>, query?: array<string, string>, catch_all?: bool, expect: array<string, array{0: int, 1: int}>, sharing?: array<string, array{0: int, 1: int}>}>
     */
    public static function routes(): array
    {
        $mailbox = 'domains/{domain}/mailboxes/{mailbox}';

        return [
            // Collections every signed-in user may open; what they contain is scoped (DomainScopeTest).
            'GET dashboard' => ['expect' => self::everyone(200)],
            'GET search' => ['query' => ['q' => 'john'], 'expect' => self::everyone(200)],
            'GET addresses' => ['expect' => self::everyone(200)],
            'GET me/pinned-domains' => ['expect' => self::everyone(200)],
            'GET domains' => ['expect' => self::everyone(200)],
            'GET domains/names' => ['expect' => self::everyone(200)],
            'GET blocked-addresses' => ['expect' => self::everyone(200)],
            'GET audit-log' => ['expect' => self::everyone(200)],
            'GET audit-log/actors' => ['expect' => self::everyone(200)],

            // Pinning needs only a role on the domain.
            'PUT me/pinned-domains/{domain}' => ['expect' => self::read(204)],
            'DELETE me/pinned-domains/{domain}' => ['expect' => self::read(204)],

            // Creating a domain and managing blocked addresses: server admins; anyone else is refused.
            'POST domains' => ['body' => ['name' => 'new.{domain}'], 'expect' => self::serverAdmin(201)],
            'POST blocked-addresses' => ['body' => ['address' => 'new@{domain}'], 'expect' => self::serverAdmin(201)],
            'DELETE blocked-addresses/{address}' => ['expect' => self::serverAdmin(204)],

            // Deleting a domain: admins; a manager or viewer of it gets 403, anyone else 404.
            'DELETE domains/{domain}' => ['body' => ['confirm_name' => '{domain}'], 'expect' => self::domainAdmin(204)],

            // Domain limits: any role reads them; only admins change them.
            'GET domains/{domain}/settings' => ['expect' => self::read(200)],
            'PATCH domains/{domain}/settings' => ['body' => ['max_mailboxes' => 10], 'expect' => self::domainAdmin(200)],

            // DNS health: any role reads the last check; admins and managers ask for a new one (1 a minute per domain).
            'GET domains/{domain}/dns' => ['expect' => self::read(200)],
            'POST domains/{domain}/dns/recheck' => ['expect' => self::write(202)],

            // DKIM keys: any role reads them; only admins (with step-up) queue the helper's tasks. `{selector}`
            // is the domain's pending key es20261001; activate forces past the DNS check.
            'GET domains/{domain}/dkim' => ['expect' => self::read(200)],
            'POST domains/{domain}/dkim/generate' => ['expect' => self::domainAdmin(202)],
            'POST domains/{domain}/dkim/inspect' => ['expect' => self::domainAdmin(202)],
            'POST domains/{domain}/dkim/{selector}/activate' => ['body' => ['force' => true], 'expect' => self::domainAdmin(202)],
            'POST domains/{domain}/dkim/{selector}/retire' => ['expect' => self::domainAdmin(202)],

            // Self-service switches: any role reads them; admins and managers set them.
            'GET domains/{domain}/self-service' => ['expect' => self::read(200)],
            'PATCH domains/{domain}/self-service' => ['body' => ['aliases' => true], 'expect' => self::write(200)],

            // Access: any role reads who has it; sharing (admins, and managers with managers_can_share)
            // changes and removes it. Removing your own needs no sharing (DomainAccessTest).
            'GET domains/{domain}/access' => ['expect' => self::read(200)],
            'PATCH domains/{domain}/access/{user}' => ['body' => ['role' => 'manager'], 'expect' => self::domainAdmin(200), 'sharing' => self::write(200)],
            'DELETE domains/{domain}/access/{user}' => ['expect' => self::domainAdmin(204), 'sharing' => self::write(204)],

            // Invitations to a domain: sharing. `{invitation}` is the domain's own pending invitation.
            'POST domains/{domain}/access/invitations' => [
                'body' => ['email' => 'newcomer@console.test', 'role' => 'viewer', 'locale' => 'en'],
                'expect' => self::domainAdmin(201), 'sharing' => self::write(201),
            ],
            'POST domains/{domain}/access/invitations/{invitation}/resend' => ['expect' => self::domainAdmin(200), 'sharing' => self::write(200)],
            'DELETE domains/{domain}/access/invitations/{invitation}' => ['expect' => self::domainAdmin(204), 'sharing' => self::write(204)],

            // Invitations through the Users screen: server admins; anyone else gets 404, as for the screen.
            'GET users/invitations' => ['expect' => self::usersScreen(200)],
            'POST users/invitations' => [
                'body' => ['email' => 'newcomer@console.test', 'role' => 'viewer', 'domain' => '{domain}', 'locale' => 'en'],
                'expect' => self::usersScreen(201),
            ],
            'POST users/invitations/{invitation}/resend' => ['expect' => self::usersScreen(200)],
            'DELETE users/invitations/{invitation}' => ['expect' => self::usersScreen(204)],

            // The Users screen: server admins; anyone else gets 404, as if no user existed. `{user}` is the
            // domain's member (a viewer of it), so the grant rows change and remove their grant there.
            'GET users' => ['expect' => self::usersScreen(200)],
            'GET users/{user}' => ['expect' => self::usersScreen(200)],
            'PATCH users/{user}' => ['body' => ['name' => 'Member'], 'expect' => self::usersScreen(200)],
            'DELETE users/{user}' => ['expect' => self::usersScreen(204)],
            'POST users/{user}/grants' => ['body' => ['domain' => '{domain}', 'role' => 'manager'], 'expect' => self::usersScreen(200)],
            'DELETE users/{user}/grants/{domain}' => ['expect' => self::usersScreen(204)],
            'POST users/{user}/two-factor/reset' => ['expect' => self::usersScreen(204)],

            // Reads inside a domain: any role on it.
            'GET domains/{domain}/addresses/check' => ['query' => ['local' => 'newbie'], 'expect' => self::read(200)],
            'GET domains/{domain}/mailboxes' => ['expect' => self::read(200)],
            "GET {$mailbox}" => ['expect' => self::read(200)],
            "GET {$mailbox}/usage" => ['expect' => self::read(200)],
            'GET domains/{domain}/forwarding' => ['expect' => self::read(200)],

            // Writes inside a domain: admins and managers; a viewer gets 403.
            'POST domains/{domain}/mailboxes' => [
                'body' => ['local' => 'anna', 'password' => 'correct-horse-9', 'quota_bytes' => 2147483648],
                'expect' => self::write(201),
            ],
            "PATCH {$mailbox}" => ['body' => ['display_name' => 'John Smith'], 'expect' => self::write(200)],
            "POST {$mailbox}/password" => ['body' => ['generate' => true], 'expect' => self::write(200)],
            "POST {$mailbox}/welcome" => ['body' => ['to' => 'someone@example.net', 'locale' => 'en'], 'expect' => self::write(202)],
            "POST {$mailbox}/disable" => ['expect' => self::write(204)],
            "POST {$mailbox}/enable" => ['expect' => self::write(204)],
            "DELETE {$mailbox}" => ['expect' => self::write(200)],
            'POST domains/{domain}/mailboxes/bulk' => [
                'body' => ['locals' => ['john'], 'action' => 'quota', 'quota_bytes' => 2147483648],
                'expect' => self::write(200),
            ],
            'PUT domains/{domain}/forwarding/{local}' => ['body' => ['targets' => ['someone@example.net']], 'expect' => self::write(200)],
            'DELETE domains/{domain}/forwarding/{local}' => ['expect' => self::write(204)],
            // The catch-all: the same roles, on a server that supports it (seed()), on domains that have one.
            'PUT domains/{domain}/forwarding/@' => ['body' => ['targets' => ['someone@example.net']], 'catch_all' => true, 'expect' => self::write(200)],
            'DELETE domains/{domain}/forwarding/@' => ['catch_all' => true, 'expect' => self::write(204)],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> "GET dashboard as viewer" => [route, principal] */
    public static function cases(): array
    {
        $cases = [];
        foreach (array_keys(self::routes()) as $route) {
            foreach ([...self::PRINCIPALS, self::MAILBOX] as $principal) {
                $cases["{$route} as {$principal}"] = [$route, $principal];
            }
        }

        return $cases;
    }

    /** @return array<string, array{0: string, 1: string}> the cases of the rows with `sharing` expectations */
    public static function sharingCases(): array
    {
        return array_filter(self::cases(), fn (array $case) => isset(self::routes()[$case[0]]['sharing']));
    }

    /** Builds the guide world and the four principals; returns them keyed by principal. */
    public static function seed(): array
    {
        config(['emailsquire.require_two_factor' => 'off']);
        GuideFixture::load('trixie');
        // As if doctor's postfix.alias_maps check were OK on this host: catch-alls may be set.
        Cache::put(CatchAllSupport::CACHE_KEY, AliasMaps::SUPPORTED, CatchAllSupport::TTL_SECONDS);

        foreach ([self::A_ID => 'a.org', self::B_ID => 'b.org'] as $id => $name) {
            self::guide('virtual_domains')->insert(['id' => $id, 'name' => $name]);
            self::guide('virtual_users')->insert(['domain_id' => $id, 'email' => "john@{$name}", 'password' => '{BLF-CRYPT}x', 'quota' => 0]);
            self::guide('virtual_aliases')->insert(['domain_id' => $id, 'source' => "jack@{$name}", 'destination' => "john@{$name}"]);
            BlockedAddress::query()->forceCreate(['address' => "spam@{$name}", 'reason' => null]);
            DomainDkim::query()->forceCreate(['domain_id' => $id, 'domain_name' => $name, 'selector' => self::SELECTOR, 'txt' => 'v=DKIM1; k=rsa; p=MIIB', 'state' => 'pending']);
        }

        $users = [];
        foreach (self::PRINCIPALS as $principal) {
            $users[$principal] = User::factory()->create([
                'email' => "{$principal}@console.test",
                'is_server_admin' => $principal === 'admin',
                'password_set_at' => now(),
            ]);
        }
        self::grant($users['manager'], 'a.org', 'manager');
        self::grant($users['viewer'], 'a.org', 'viewer');

        $target = User::factory()->create(['email' => self::TARGET_EMAIL, 'password_set_at' => now()]);
        self::grant($target, 'a.org', 'viewer');
        self::grant($target, 'b.org', 'viewer');

        foreach (self::DOMAINS as $name) {
            $member = User::factory()->create([
                'email' => sprintf(self::MEMBER_EMAIL, $name),
                'password_set_at' => now(),
                'two_factor_secret' => encrypt('matrix-secret'),
                'two_factor_confirmed_at' => now(),
            ]);
            self::grant($member, $name, 'viewer');
        }

        foreach ([self::A_ID => 'a.org', self::B_ID => 'b.org'] as $id => $name) {
            Invitation::query()->forceCreate([
                'token_hash' => hash('sha256', "matrix-{$name}"),
                'email' => self::INVITED_EMAIL,
                'domain_id' => $id,
                'domain_name' => $name,
                'role' => 'viewer',
                'invited_by' => $users['admin']->id,
                'expires_at' => now()->addHours(72),
            ]);
        }

        return $users;
    }

    /**
     * What a row needs beyond the world, before its cases run: with `catch_all`, the catch-all @ → john@
     * on both domains.
     *
     * @param  array{catch_all?: bool}  $row
     */
    public static function prepare(array $row): void
    {
        if (! ($row['catch_all'] ?? false)) {
            return;
        }
        foreach ([self::A_ID => 'a.org', self::B_ID => 'b.org'] as $id => $name) {
            self::guide('virtual_aliases')->insert(['domain_id' => $id, 'source' => "@{$name}", 'destination' => "john@{$name}"]);
        }
    }

    /** The mailbox column's principal: john@a.org as the `mailbox` guard's provider builds it. */
    public static function mailboxPrincipal(): MailboxPrincipal
    {
        $principal = Auth::guard('mailbox')->getProvider()->retrieveById('john@a.org');
        if (! $principal instanceof MailboxPrincipal) {
            throw new RuntimeException('john@a.org is not a mailbox; seed() first.');
        }

        return $principal;
    }

    public static function grant(User $user, string $domain, string $role): DomainGrant
    {
        $id = self::guide('virtual_domains')->where('name', $domain)->value('id');

        return DomainGrant::query()->forceCreate([
            'user_id' => $user->id,
            'domain_id' => $id,
            'domain_name' => $domain,
            'role' => $role,
        ]);
    }

    /** @param  array{query?: array<string, string>}  $row */
    public static function url(string $uri, string $domain, array $row): string
    {
        $path = strtr($uri, [
            '{domain}' => $domain,
            '{mailbox}' => 'john',
            '{local}' => 'jack',
            '{selector}' => self::SELECTOR,
            '{address}' => "spam@{$domain}",
            '{user}' => (string) User::query()->where('email', str_starts_with($uri, 'users/') ? sprintf(self::MEMBER_EMAIL, $domain) : self::TARGET_EMAIL)->value('id'),
            '{invitation}' => (string) Invitation::query()->where('email', self::INVITED_EMAIL)->where('domain_name', $domain)->value('id'),
        ]);
        $query = isset($row['query']) ? '?'.http_build_query($row['query']) : '';

        return "/api/v1/{$path}{$query}";
    }

    /**
     * @param  array{body?: array<string, mixed>}  $row
     * @return array<string, mixed>
     */
    public static function body(array $row, string $domain): array
    {
        $body = $row['body'] ?? [];
        array_walk_recursive($body, function (mixed &$value) use ($domain) {
            if (is_string($value)) {
                $value = str_replace('{domain}', $domain, $value);
            }
        });

        return $body;
    }

    /** @return array<string, array{0: int, 1: int}> every signed-in user, wherever */
    private static function everyone(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [$ok, $ok], 'viewer' => [$ok, $ok], 'none' => [$ok, $ok], self::MAILBOX => self::UNAUTHENTICATED];
    }

    /** @return array<string, array{0: int, 1: int}> any role on the domain; no role is a missing domain */
    private static function read(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [$ok, 404], 'viewer' => [$ok, 404], 'none' => [404, 404], self::MAILBOX => self::UNAUTHENTICATED];
    }

    /** @return array<string, array{0: int, 1: int}> admins and managers; a viewer sees the domain but may not write */
    private static function write(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [$ok, 404], 'viewer' => [403, 404], 'none' => [404, 404], self::MAILBOX => self::UNAUTHENTICATED];
    }

    /** @return array<string, array{0: int, 1: int}> admins only, on a domain the others may see */
    private static function domainAdmin(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [403, 404], 'viewer' => [403, 404], 'none' => [404, 404], self::MAILBOX => self::UNAUTHENTICATED];
    }

    /** @return array<string, array{0: int, 1: int}> the Users screen: admins only; to anyone else it does not exist */
    private static function usersScreen(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [404, 404], 'viewer' => [404, 404], 'none' => [404, 404], self::MAILBOX => self::UNAUTHENTICATED];
    }

    /** @return array<string, array{0: int, 1: int}> admins only, on no particular domain */
    private static function serverAdmin(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [403, 403], 'viewer' => [403, 403], 'none' => [403, 403], self::MAILBOX => self::UNAUTHENTICATED];
    }

    private static function guide(string $table): Builder
    {
        return DB::table(GuideSchema::table($table));
    }
}
