<?php

namespace Tests\Support;

use App\MailServer\GuideSchema;
use App\Models\BlockedAddress;
use App\Models\DomainGrant;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The §6 role matrix as data, shared by MatrixTest (which runs it) and RouteAuthorizationTest (which
 * fails when a `can:` route is missing from it).
 *
 * The world: the guide holds a.org and b.org, each with the mailbox john@, the forwarding jack@ → john@
 * and the blocked address spam@. The principals are a server admin, a manager and a viewer of a.org,
 * and a user with no grant. Nobody has a grant on b.org.
 *
 * To add a route: add one row to routes(), keyed "METHOD uri" exactly as registered (without
 * `api/v1/`), with the body and query it needs to succeed for the admin and the expected status per
 * principal as [on a.org, on b.org]. The helpers below spell the common shapes.
 */
final class AuthorizationMatrix
{
    public const PRINCIPALS = ['admin', 'manager', 'viewer', 'none'];

    public const DOMAINS = ['a.org', 'b.org'];

    public const A_ID = 20;

    public const B_ID = 30;

    /**
     * @return array<string, array{body?: array<string, mixed>, query?: array<string, string>, expect: array<string, array{0: int, 1: int}>}>
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
            "POST {$mailbox}/disable" => ['expect' => self::write(204)],
            "POST {$mailbox}/enable" => ['expect' => self::write(204)],
            "DELETE {$mailbox}" => ['expect' => self::write(200)],
            'POST domains/{domain}/mailboxes/bulk' => [
                'body' => ['locals' => ['john'], 'action' => 'quota', 'quota_bytes' => 2147483648],
                'expect' => self::write(200),
            ],
            'PUT domains/{domain}/forwarding/{local}' => ['body' => ['targets' => ['someone@example.net']], 'expect' => self::write(200)],
            'DELETE domains/{domain}/forwarding/{local}' => ['expect' => self::write(204)],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> "GET dashboard as viewer" => [route, principal] */
    public static function cases(): array
    {
        $cases = [];
        foreach (array_keys(self::routes()) as $route) {
            foreach (self::PRINCIPALS as $principal) {
                $cases["{$route} as {$principal}"] = [$route, $principal];
            }
        }

        return $cases;
    }

    /** Builds the guide world and the four principals; returns them keyed by principal. */
    public static function seed(): array
    {
        config(['emailsquire.require_two_factor' => 'off']);
        GuideFixture::load('trixie');

        foreach ([self::A_ID => 'a.org', self::B_ID => 'b.org'] as $id => $name) {
            self::guide('virtual_domains')->insert(['id' => $id, 'name' => $name]);
            self::guide('virtual_users')->insert(['domain_id' => $id, 'email' => "john@{$name}", 'password' => '{BLF-CRYPT}x', 'quota' => 0]);
            self::guide('virtual_aliases')->insert(['domain_id' => $id, 'source' => "jack@{$name}", 'destination' => "john@{$name}"]);
            BlockedAddress::query()->forceCreate(['address' => "spam@{$name}", 'reason' => null]);
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

        return $users;
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
            '{address}' => "spam@{$domain}",
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
        return ['admin' => [$ok, $ok], 'manager' => [$ok, $ok], 'viewer' => [$ok, $ok], 'none' => [$ok, $ok]];
    }

    /** @return array<string, array{0: int, 1: int}> any role on the domain; no role is a missing domain */
    private static function read(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [$ok, 404], 'viewer' => [$ok, 404], 'none' => [404, 404]];
    }

    /** @return array<string, array{0: int, 1: int}> admins and managers; a viewer sees the domain but may not write */
    private static function write(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [$ok, 404], 'viewer' => [403, 404], 'none' => [404, 404]];
    }

    /** @return array<string, array{0: int, 1: int}> admins only, on a domain the others may see */
    private static function domainAdmin(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [403, 404], 'viewer' => [403, 404], 'none' => [404, 404]];
    }

    /** @return array<string, array{0: int, 1: int}> admins only, on no particular domain */
    private static function serverAdmin(int $ok): array
    {
        return ['admin' => [$ok, $ok], 'manager' => [403, 403], 'viewer' => [403, 403], 'none' => [403, 403]];
    }

    private static function guide(string $table): Builder
    {
        return DB::table(GuideSchema::table($table));
    }
}
