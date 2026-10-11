<?php

return [

    'guide_schema' => env('EMAILSQUIRE_GUIDE_SCHEMA', 'mailserver'),
    'quota_default_bytes' => 5368709120,
    'app_user' => 'emailsquire', // OS user that runs PHP-FPM, the queue and the scheduler
    'vmail_user' => 'vmail',
    'rspamd_user' => '_rspamd',
    'paths' => [
        // A relative path is taken from the app's base directory (the e2e run's fixture main.cf).
        'postfix_main_cf' => (static fn (string $path): string => str_starts_with($path, '/') ? $path : base_path($path))(
            (string) env('EMAILSQUIRE_POSTFIX_MAIN_CF', '/etc/postfix/main.cf'),
        ),
        'mail_root' => env('EMAILSQUIRE_MAIL_ROOT', '/var/vmail'),
        'trash_dir' => env('EMAILSQUIRE_TRASH_DIR', '/var/vmail/.emailsquire-trash'),
        'dkim_dir' => env('EMAILSQUIRE_DKIM_DIR', '/var/lib/rspamd/dkim'),
        // rspamd's selector_map, which the DKIM helper rewrites as _rspamd. It lives in a directory of its
        // own (root:_rspamd 0770), never in rspamd_config_dir itself: point selector_map in the guide's
        // local.d/dkim_signing.conf at it. The doctor (fs.dkim_selector_map) checks all three.
        'dkim_selector_map' => env('EMAILSQUIRE_DKIM_SELECTOR_MAP', '/etc/rspamd/emailsquire/dkim_selectors.map'),
        'rspamd_config_dir' => env('EMAILSQUIRE_RSPAMD_CONFIG_DIR', '/etc/rspamd'),
        'fpm_php_ini' => env('EMAILSQUIRE_FPM_PHP_INI', '/etc/emailsquire/php.ini'),
    ],
    'doveadm' => [
        'url' => env('DOVEADM_URL', 'http://127.0.0.1:8080/doveadm/v1'),
        'api_key' => env('DOVEADM_API_KEY'),
        'timeout' => 3,
    ],
    'dovecot_auth' => [
        'socket' => env('DOVECOT_AUTH_SOCKET', '/run/dovecot/emailsquire-auth'),
        'timeout' => 10, // Dovecot delays FAIL by auth_failure_delay (2 s)
    ],

    // Sender of every email. The default is a name on this host; set a real mailbox to make SPF and DKIM pass.
    'mail_from' => env('EMAILSQUIRE_MAIL_FROM', 'emailsquire@'.gethostname()),

    // The welcome email (POST …/mailboxes/{mailbox}/welcome) tells a mailbox's user how to set up their
    // mail app: IMAP on this host at 993 (SSL/TLS) and SMTP at 587 (STARTTLS). Use the name on the TLS
    // certificate; the doctor (mail.host) warns about a name without a dot, such as a bare hostname.
    'mail_host' => env('EMAILSQUIRE_MAIL_HOST', gethostname() ?: 'localhost'),
    // The server's public addresses (comma-separated, IPv4 and IPv6) for the DNS checks: each needs a PTR
    // naming mail_host, and SPF must cover one. Empty uses mail_host's A and AAAA records; set it on a
    // server behind NAT, whose own address is not the one the world sees.
    'server_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('EMAILSQUIRE_SERVER_IPS', ''))), fn (string $ip) => $ip !== '')),
    'dns' => [
        // Seconds one domain's check may spend asking DNS; later lookups count as failed. One lookup
        // against a dead resolver can take about 30 s (glibc: 5 s × 2 attempts × 3 name servers), so a
        // run ends within budget + 30 s, inside CheckDomainDns's 100 s timeout.
        'budget' => 30,
    ],
    // Webmail address printed in the welcome email and shown in the mailbox portal; empty leaves it out.
    'webmail_url' => env('EMAILSQUIRE_WEBMAIL_URL', ''),
    // Who mailbox owners should contact when their domain has no manager (the portal's Help screen):
    // optional free text, shown as plain text (never HTML), at most 200 characters. Empty shows a generic line.
    'support_contact' => env('EMAILSQUIRE_SUPPORT_CONTACT', ''),
    // Local parts a mailbox owner may not take as an alias in the portal (compared case-insensitively):
    // the role addresses other servers and people expect to reach the domain's operators. Server admins
    // and managers can still forward them from the console.
    'owner_reserved_locals' => ['postmaster', 'abuse', 'hostmaster', 'webmaster', 'admin', 'administrator', 'root', 'security', 'mailer-daemon', 'noc', 'ssladmin', 'ssl-admin', 'info'],

    'hibp' => env('EMAILSQUIRE_HIBP', true), // refuse console passwords found in Have I Been Pwned
    // Who must confirm two-factor authentication before using the console (RequireTwoFactor): admins
    // (server admins), everyone, or off. off is for the test suite only; the doctor fails it in production.
    'require_two_factor' => env('EMAILSQUIRE_REQUIRE_TWO_FACTOR', 'admins'),
    // Whether a domain's managers may share it (grants and invitations); server admins always may.
    'managers_can_share' => (bool) env('EMAILSQUIRE_MANAGERS_CAN_SHARE', false),
    'admin_setup_expire_minutes' => 4320, // a create-admin setup link lives 72 h
    'trash_retention_days' => 30,
    'source_lock_timeout' => 5, // seconds to wait for another request that is changing the same address

    'testing' => [
        // Laravel skips CSRF checks while unit testing. Only the security tests set this true, to prove
        // the check is enforced; nothing else should touch it.
        'enforce_csrf' => false,
    ],

];
