# EmailSquire: backend plan

Oct 9, 2026 · draft 2

This plan covers the backend for **EmailSquire**, a replacement for ISPmail Admin. It is built with **Laravel** and targets servers built with the ISPmail guide's **Trixie edition only** (Debian 13, Postfix, Dovecot 2.4, MariaDB, rspamd, Roundcube, Apache with certbot). It also covers the feature set in `ISPmail Admin – UI redesign brief.md`.

The core rule is that **Postfix, Dovecot and Roundcube must not notice the admin app exists.** The guide's three tables stay as they are, and the queries in `/etc/postfix/mysql-*.cf` and Dovecot's SQL config keep working unchanged. Everything the app needs beyond that goes into its own database schema.

## 1. What is already on the server

| Component | What the app relies on | How the app talks to it |
|---|---|---|
| MariaDB `mailserver` DB | `virtual_domains`, `virtual_users`, `virtual_aliases` (schema from the guide's installer, `ispmail.sh`). Postfix and Dovecot read them live, so a committed row takes effect immediately with no reload. | SQL, with a new least-privilege DB user |
| Postfix | Virtual domain, mailbox and alias maps (`/etc/postfix/mariadb-*.cf`), the `email2email` map, and a sender login map (see below) | Nothing directly; only through the tables. Local SMTP (port 25 on localhost) for sending invitation and reset emails. |
| Dovecot 2.4 | SQL passdb/userdb; `mail_home = /var/vmail/%{user \| domain}/%{user \| username}` with `~/Maildir`; quota plugin (`quota_storage_size = 5G`; the userdb query returns `IF(quota > 0, CONCAT(quota, 'B'), NULL)`, so `0` means "use the default"). The installer does **not** enable the doveadm HTTP API; installing the app adds it and a dedicated auth socket (section 8). | Doveadm HTTP API on localhost (quota, kick, user lookup), and the Dovecot auth socket `/run/dovecot/emailsquire-auth` (password checks) |
| rspamd | DKIM keys at `/var/lib/rspamd/dkim/$domain.$selector.key` (owned by `_rspamd`), selectors in `/etc/rspamd/dkim_selectors.map` | Files written by a helper (phase 4) |
| Roundcube | Writes `{CRYPT}$2y$…` hashes through its password plugin, and manages Sieve (vacation, filters) through ManageSieve | Nothing. The app has to accept the hashes Roundcube writes. |
| Apache with certbot | Already terminates TLS for webmail. Roundcube runs under **mod_php** (`libapache2-mod-php`). | Proxies `.php` requests to the app's own php-fpm master, on its own vhost. Needs `a2enmod proxy_fcgi`; mod_php for Roundcube stays as it is. |
| PHP 8.4 (Trixie packages) | The runtime for the app and helpers | Debian packages only. No third-party PHP repository (such as Ondřej Surý's), so security updates come through `apt`. |

Facts from the stack that shape the design:

- **Deleting a row does not delete mail.** The maildir stays on disk. If someone later creates a mailbox with the same address, Dovecot gives them the old owner's mail. The original tool has this problem, and the new backend must clean up maildirs.
- **An alias beats a mailbox.** A `virtual_aliases` row whose source is an existing mailbox redirects that mailbox's mail. Creating one is a privileged act.
- **An alias may also be a send-as permission.** It depends on the server's `smtpd_sender_login_maps`. The guide's `mariadb-sender-login-maps.cf` unions in `virtual_aliases`, so an alias lets its destination send as the source. The installer (v0.5) sets that map, but a later step overrides it with `mariadb-email2email.cf`, which allows only the user's own address. `doctor` reports which map is active. The app always treats alias creation as if it grants send-as, because that is the safe assumption.
- **"Keep a copy" already works.** Forwarding with a copy is two rows: `me → external` and `me → me`. No schema change is needed.
- **`quota = 0` means the 5 GB default, not unlimited.** That's how Trixie works, and the app follows it. "Unlimited" is not offered at all; nobody needs it. The UI brief says "0 means unlimited", which is Bookworm behaviour; the brief needs correcting.
- **A Trixie server can still hold Bookworm data.** The guide's upgrade path is a plain dump and restore with no `ALTER`. A Trixie server may therefore lack the `UNIQUE KEY (name)` on `virtual_domains` and may still hold `{SHA256-CRYPT}` or `{SHA512-CRYPT}` hashes. The app supports only Trixie, but it must handle that data; `doctor` (section 8) reports it.

## 2. Stack

| Concern | Choice |
|---|---|
| Runtime | PHP 8.4 from Debian Trixie, with `php8.4-fpm`, `-cli`, `-mysql`, `-intl` (IDN), `-mbstring`, `-xml` and `-curl` |
| Framework | Laravel 13 (requires PHP ^8.3, so Trixie's 8.4 fits) |
| Auth | **Fortify**, headless: console sign-in, TOTP 2FA and recovery codes, password reset, password confirmation (used as step-up). **`laragear/webauthn`** for passkeys, chosen because it is JSON-API friendly; check it is still maintained at phase 0. A custom guard handles mailbox users (section 5.2). |
| API | Routes under `/api/v1` in the **`web` middleware group**, so they get session auth and CSRF. It is a same-origin SPA with no third-party clients, so Sanctum's token machinery isn't needed. |
| API contract | OpenAPI 3.1 generated from the code with `dedoc/scramble`. A TypeScript client is generated from that for the frontend. Errors use RFC 9457 `application/problem+json`, rendered by the exception handler. |
| Validation | Form Requests plus custom rules (`MailAddress`, `MailDomain`, `ForwardingTargets`) built on one `AddressNormalizer` service |
| DB access | Eloquent for app tables. For the guide's tables: thin Eloquent models plus a repository layer that is the **only** code allowed to write them (section 4.1). |
| Queue, cache, sessions | All on the `database` driver, in the `emailsquire` schema. No Redis dependency; `redis-server` is on the box for rspamd, but the app doesn't share it. |
| Mail | Laravel Mail over SMTP to `localhost:25` |
| Frontend | React with Vite. A built SPA is served by one Blade shell view that carries a CSP nonce. Not part of this plan. |
| Helpers | **Plain PHP CLI scripts with no framework and no Composer autoload** (section 3). They are small, read-only to everything except their own target directory, and tested by the same test suite. |

Why a JSON API and not Inertia: the policy matrix (section 6) is tested by calling endpoints, the mailbox portal shares those endpoints, and a later CLI or automation can reuse them. Inertia would remove the generated client, but it ties every screen to a server round-trip shape. If the frontend side prefers it, it's a contained change: the controllers and policies stay the same.

## 3. Process architecture

```
              Internet
                 │ HTTPS (admin.example.org)
          ┌──────▼──────┐
          │   Apache    │  TLS, HSTS, optional IP allowlist
          └──────┬──────┘
                 │ FastCGI, unix socket /run/emailsquire/fpm.sock
          ┌──────▼───────────────────────┐   doveadm HTTP API (127.0.0.1, API key)
          │ emailsquire-fpm.service        ├──────────────────────────────► Dovecot
          │ own php-fpm master, user     │
          │ emailsquire; exec/proc_* off   │
          └──────┬───────────────────────┘
                 │
          ┌──────┴───────────────────────┐
          │ emailsquire-queue.service      │  php artisan queue:work (emails, DNS checks)
          │ emailsquire-schedule.timer     │  php artisan schedule:run (pruning, rechecks)
          └──────┬───────────────────────┘
                 │ SQL, one connection (user emailsquire_app)
          ┌──────▼──────────────────────────────┐
          │ MariaDB                             │
          │  mailserver.virtual_*  (guide)      │
          │  emailsquire.*           (app-owned)  │
          └──────▲──────────────────▲───────────┘
                 │ helper_tasks (SELECT/UPDATE only, unix_socket auth)
   ┌─────────────┴──────┐   ┌───────┴──────────────┐
   │ maildir helper     │   │ dkim helper          │
   │ user: vmail        │   │ user: _rspamd        │
   │ plain PHP, timer   │   │ plain PHP, ph. 4     │
   └────────────────────┘   └──────────────────────┘
```

Design decisions:

- **The app gets its own php-fpm master, not just a pool.** Running `emailsquire-fpm.service` as a separate systemd unit with its own config means section 9's sandboxing applies to the admin app alone. Roundcube's PHP setup is left untouched. Its `php.ini` disables `exec`, `shell_exec`, `system`, `passthru`, `proc_open` and `popen`, because the web app never needs to run a program; that is the helpers' job.
- **No root, anywhere.** Maildir work runs as `vmail`, the owner of `/var/vmail`. DKIM key work runs as `_rspamd`. The web process cannot touch either directory.
- **The helpers are framework-free on purpose.** Booting Laravel as `vmail` would mean `vmail` can read `.env`, which holds `APP_KEY` and the app's DB password, and would load the whole `vendor/` tree into a process that writes mail storage. Each helper is about 200 lines: PDO over the MariaDB unix socket, which needs no password; strict path checks; `rename()`. It runs as a oneshot on a systemd timer.
- **Helpers pull; they don't take orders.** The web process only inserts a row into `emailsquire.helper_tasks` (for example "archive maildir of `x@example.org`"). The name is not `jobs`, because Laravel's queue uses that. The helper does not trust that row. It:
  1. re-reads the task,
  2. checks that `x@example.org` really has no `virtual_users` row any more,
  3. derives the path itself from the configured mail root, the domain and the local part,
  4. checks that the result is a real directory under `/var/vmail` with no symlinks on the path,
  5. only then moves it.

  A compromised web process can therefore request work, but cannot pick paths or trigger anything that the database state contradicts.
- **The helper archives instead of deleting.** A deleted mailbox's maildir is moved to `/var/vmail/.emailsquire-trash/<domain>/<local>-<timestamp>`. That name can't collide with a domain, because domain names never start with a dot. A daily run purges entries older than `trash_retention_days` (default 30). Admins can restore within that window. A restore needs the mailbox to have been re-created, and the helper refuses if the target maildir already exists.
- **Address reuse is blocked until the archive has moved.** If a maildir task for an address is still pending or failed, creating a mailbox with that address returns `409`. This closes the "new owner inherits old mail" hole even when the helper is behind.

## 4. Data model

### 4.1 The guide's schema: read and write, never alter

**One connection, schema-qualified tables.** Laravel transactions are per connection. If `mailserver` and `emailsquire` were two connections, deleting a domain could commit the guide side and roll back the app side. So there is a single `mysql` connection, with `emailsquire` as its default database. The guide models name their table with the schema:

```php
final class Mailbox extends Model
{
    protected $table = 'mailserver.virtual_users';
    public $timestamps = false;
    protected $hidden = ['password'];
    protected $guarded = ['*'];   // writes only through MailboxRepository
}
```

That way one `DB::transaction()` covers a domain delete across both schemas, along with its audit entry. Mass assignment is closed on these models. `MailboxRepository`, `AliasRepository` and `DomainRepository` set attributes explicitly, and an architecture test (Pest `arch()->expect(...)->toOnlyBeUsedIn(...)`) fails if the guide models are used outside `App\MailServer`.

Privileges for a new DB user. Don't reuse the guide's `mailadmin`, which has `ALL PRIVILEGES` and is shared with Roundcube:

```sql
-- Web, queue and scheduler (password in .env)
CREATE USER 'emailsquire_app'@'localhost' IDENTIFIED BY '…';
GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.virtual_domains TO 'emailsquire_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.virtual_users   TO 'emailsquire_app'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON mailserver.virtual_aliases TO 'emailsquire_app'@'localhost';
-- emailsquire.* is granted per table (printed by `php artisan emailsquire:grants`), so that
-- audit_log can get INSERT, SELECT only. MariaDB can't revoke a table privilege from a DB-wide grant.
GRANT INSERT, SELECT ON emailsquire.audit_log TO 'emailsquire_app'@'localhost';
GRANT SELECT ON emailsquire.migrations TO 'emailsquire_app'@'localhost';  -- read-only, so `doctor` can see pending migrations

-- Schema migrations: `sudo php artisan migrate --database=migrator` during install/upgrade.
-- The `migrator` connection is MariaDB root over unix_socket (Debian's default). The running app
-- never has DDL rights, and Laravel migrations only ever touch the emailsquire schema.

-- Helpers: unix_socket auth, DB user name = OS user name, so no password exists at all
CREATE USER 'vmail'@'localhost' IDENTIFIED VIA unix_socket;
GRANT SELECT (`email`) ON mailserver.virtual_users TO 'vmail'@'localhost';  -- the helper only checks that a mailbox still exists, so it never sees password hashes
GRANT SELECT, UPDATE ON emailsquire.helper_tasks  TO 'vmail'@'localhost';
CREATE USER '_rspamd'@'localhost' IDENTIFIED VIA unix_socket;            -- phase 4
GRANT SELECT ON mailserver.virtual_domains      TO '_rspamd'@'localhost';
GRANT SELECT, UPDATE ON emailsquire.helper_tasks  TO '_rspamd'@'localhost';
```

Each helper's task query filters on its own task types. A view per helper (`helper_tasks_maildir`, `helper_tasks_dkim`) would let the grants enforce that too; that's worth doing in phase 4, once there are two helpers.

Invariants the app enforces, because the schema doesn't:

| Invariant | Why |
|---|---|
| Addresses and domains are lowercase ASCII; IDN domains are stored as punycode (`idn_to_ascii`, UTS #46) | Postfix lookups are exact-match on the stored string. A mixed-case or Unicode row can silently fail to match. |
| `virtual_users.email`'s domain part equals the name of the row's `domain_id` | Otherwise a domain delete leaves orphans or removes the wrong rows |
| `virtual_aliases.source`'s domain part equals the name of the row's `domain_id`; a catch-all source is `@domain` | Same reason |
| Domain names are unique | Trixie has a `UNIQUE KEY`, but restored Bookworm data may not. The app checks before insert either way, and `doctor` suggests adding the key. |
| No duplicate `(source, destination)` pairs | No unique key exists. Writes to one source are serialised with `GET_LOCK('emailsquire:src:<source>', 5)` inside the transaction, released in a `finally`. |
| Column lengths: domain ≤ 50, address ≤ 100, hash ≤ 150 | Checked before insert so the user sees a clear error, not a truncation or SQL error |
| `quota` is bytes; `0` means the 5 GB default | Trixie semantics. The default size comes from config `emailsquire.quota_default_bytes`, so the UI can show "5 GB (default)". |

### 4.2 The app's own schema (`emailsquire`)

It lives in the same MariaDB instance and is created by Laravel migrations. No foreign keys point into `mailserver`. App rows reference domains by `domain_id` plus `domain_name`, and `doctor` reconciles them if someone edits the guide tables by hand (with Adminer, for example).

| Table | Purpose |
|---|---|
| `users` | People who can sign in to the console (server admins, domain managers). Separate from mailboxes, per the brief. Includes email, name, Argon2id hash (`HASH_DRIVER=argon2id`), Fortify's `two_factor_*` columns (the secret is encrypted with `APP_KEY`), `is_server_admin`, status, locale, last sign-in. |
| `webauthn_credentials` | Passkeys, from `laragear/webauthn`'s migration |
| `sessions` | Laravel's database session table. "Sign out everywhere" deletes a user's rows. The step-up time is Laravel's own `auth.password_confirmed_at` session key. |
| `password_reset_tokens` | Laravel's, used by Fortify |
| `cache`, `cache_locks`, `jobs`, `failed_jobs` | Laravel's, for rate limiters and the queue |
| `domain_grants` | `(user_id, domain_id, domain_name, role)` where role is `manager` or `viewer`. Answers the brief's "read-only viewer?" question cheaply. |
| `invitations` | Token hash, email, domain, role, inviter, expires, used or revoked |
| `domain_settings` | Limits (`max_mailboxes`, `max_quota_bytes_total`) and self-service toggles (`allow_user_forwarding`, `allow_user_aliases`, `allow_external_forwarding`) |
| `mailbox_settings` | Per-mailbox overrides of those toggles, plus `display_name` |
| `disabled_mailboxes` | Saved password hashes of disabled mailboxes (see 4.3) |
| `blocked_addresses` | Address, reason, created by. Migrated from IMA's `blacklist_email` if present. |
| `helper_tasks` | Durable queue for the helpers: type, address, status, attempts, last error, run after |
| `mailbox_usage`, `server_storage` | Usage snapshots from doveadm and the mail root's disk size, refreshed every 15 min, so lists don't call doveadm per row (phase 1) |
| `pinned_domains` | `(user_id, domain_id, domain_name, position)`, the sidebar's pinned domains (phase 1) |
| `audit_log` | Append-only. Time, actor, actor type, IP, action, object type and key, domain, JSON diff. Secrets are always redacted. The app user has `INSERT, SELECT` only on this table, never `UPDATE` or `DELETE`. |

### 4.3 "Disable mailbox" without touching the server config

The brief asks for enable and disable. The guide's schema has no flag for it, and adding one would mean editing the Postfix and Dovecot queries. The plan uses an approach that needs no config change:

- **Disable:** move the hash into `emailsquire.disabled_mailboxes`, then set `virtual_users.password` to `{BLF-CRYPT}` + a bcrypt hash of 32 random bytes that are thrown away at once. Every login fails, including Roundcube's, and Dovecot reports it as an ordinary `auth failed`. Kick open sessions through doveadm. Mail is still delivered, so nothing bounces. The `disabled_mailboxes` row is the only record that the mailbox is disabled, because the hash itself looks normal.
- **Why not a marker such as `{BLF-CRYPT}!disabled`?** Tested on Dovecot 2.4.1, an unparseable hash gives `internal auth failure`. Mail clients treat that as a temporary server error and keep retrying, and Dovecot logs it as an error.
- **Enable:** restore the saved hash.
- A password reset on a disabled mailbox updates the saved hash, not the live one.

If you also need "stop receiving mail", that requires the optional schema extension in section 10.

## 5. Authentication

Two guards share one sign-in screen. The sign-in endpoint decides from the identifier: an address on a hosted domain tries the `mailbox` guard, and anything else, or a console user's address, tries `web`. If an address is both, console sign-in takes precedence, and the portal is reached through a "manage my mailbox" link once signed in.

### 5.1 Console users (`web` guard, Fortify)

- Passwords hashed with Argon2id (Laravel `argon2id` driver; m = 64 MiB, t = 3). Validated with `Password::min(12)->uncompromised()`. `uncompromised()` calls Have I Been Pwned (HIBP) with k-anonymity. It is an outbound call, so a config flag controls it; the default is on, since the mail host has outbound HTTPS anyway.
- **2FA:**
  - Fortify TOTP with recovery codes, plus passkeys through `laragear/webauthn`. All of it, enrolment included, starts in phase 2; phase 1 signs in with a password only (decided Oct 10, 2026).
  - **Required for server admins** from phase 2: middleware blocks every route except the 2FA setup ones until it is set up.
  - Optional for domain managers, unless config requires it.
- **Onboarding and recovery:**
  - Invitations (brief 6.4) are 256-bit random tokens, stored hashed, single-use, expiring after 72 h.
  - Password reset (brief 6.5) uses Fortify's broker with a 30 min expiry. The response is the same whether or not the account exists.
- **Bootstrap:** `php artisan emailsquire:create-admin you@example.org` prints a one-time setup link. There is no default credential, no seeder that creates users in production, and no setup page exposed to the web.

### 5.2 Mailbox users (`mailbox` guard, portal, phase 3)

- A custom `UserProvider` returns a read-only `MailboxPrincipal` (implements `Authenticatable`) built from the `virtual_users` row.
- **`validateCredentials()` asks Dovecot** over the auth socket `/run/dovecot/emailsquire-auth` (section 8), with `service=emailsquire` and the client's IP as `rip=` (section 8). Dovecot already understands every scheme on the server (`BLF-CRYPT`, `CRYPT`, and restored `SHA*-CRYPT`, all verified on 2.4.1), which matters because Roundcube's password plugin writes `{CRYPT}` hashes. The attempt also appears in Dovecot's auth log. The installer sets up no fail2ban, so the app ships jails for both its own sign-in log and Dovecot auth failures.
- **Dovecot answers a failed password only after `auth_failure_delay` (2 s).** The auth client's timeout is therefore 10 s, and the portal sign-in endpoint does not add its own delay on top.
- **`retrieveById()` re-reads the row on every request** and returns `null` if it is gone or has a `disabled_mailboxes` row. Deleting or disabling a mailbox ends its portal sessions at the next request.
- **Password change:**
  - Require the current password, verified the same way.
  - Hash with `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])` and store `{BLF-CRYPT}` + the result (`$2y$…`).
  - Kick other IMAP sessions.
  - bcrypt ignores everything after 72 bytes, so the validator rejects longer passwords instead of truncating them silently.
- No email reset for mailbox users (brief 6.5). The screen tells them to contact their domain manager.

### 5.3 Sessions and request protection

- **Cookie:** `SESSION_COOKIE=__Host-emailsquire`, `SESSION_SECURE_COOKIE=true`, `same_site=strict`, `http_only=true`, no domain, path `/`. Session data stays server-side (`database` driver), so sessions can be revoked ("sign out everywhere" in brief 6.8).
- **Lifetime:** `SESSION_LIFETIME=30` minutes idle for both guards. A small middleware adds an absolute limit: 12 h for console users, 2 h for the portal. The session ID rotates on sign-in, sign-out and any role change (`session()->regenerate()`).
- **Step-up re-authentication** with Laravel's `password.confirm` middleware, timeout 300 s. Passkey confirmation counts too. Required for: deleting a domain, changing roles or grants, deleting a console user, and disabling 2FA.
- **CSRF:** Laravel's `ValidateCsrfToken`, with the SPA sending `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie. On top of that, `SameSite=Strict` and an `EnsureSameOrigin` middleware that rejects state-changing requests whose `Origin` (or `Sec-Fetch-Site`) is not same-origin.
- **Rate limits:** Fortify's `login` and `two-factor` limiters (per address and IP), a matching `mailbox-login` limiter, and `throttle:api` on writes. All use the database cache store. Every failed sign-in is logged as one line in a fixed format, and a fail2ban filter for it ships with the app.

## 6. Authorization

Laravel Policies (`DomainPolicy`, `MailboxPolicy`, `ForwardingPolicy`, `BlockedAddressPolicy`, `UserPolicy`) are the only place role logic lives. Every controller action calls `$this->authorize()`, and an architecture test enforces that.

- The resource is always **loaded from the database by route model binding**, and its domain comes from the stored row, never from the request.
- **Scoped queries:**
  - Every list query starts from `Domain::visibleTo($principal)`, `Mailbox::visibleTo($principal)` or a similar local scope. These get their domain IDs from one `DomainAccess` service.
  - Route model binding for nested routes uses `->scopeBindings()`, so `/domains/a.org/mailboxes/x` can never resolve a mailbox from `b.org`.
- **Readable URLs:** routes bind by natural key (`/api/v1/domains/{domain:name}/mailboxes/{mailbox:local}`), which the brief wants for bookmarkable pages.
- **404, not 403:** out-of-scope objects return `404`, through `Response::denyAsNotFound()` in policies, so a domain manager can't probe which domains exist.

Matrix (from brief section 3, plus the viewer role):

| Action | Server admin | Manager (granted domain) | Viewer (granted domain) | Mailbox user |
|---|---|---|---|---|
| List or read domain, mailboxes, forwarding | all | ✓ | ✓ | own mailbox only |
| Create or delete domain | ✓ | – | – | – |
| Create or delete mailbox, set quota (within domain limits) | ✓ | ✓ | – | – |
| Reset password, disable or enable mailbox | ✓ | ✓ | – | own password only, current password required |
| Forwarding or alias on any address in the domain | ✓ | ✓ | – | – |
| Forward **own** mailbox (keep copy or forward only) | ✓ | ✓ | – | if `allow_user_forwarding`; external targets only if `allow_external_forwarding` |
| Add alias **to own** mailbox | ✓ | ✓ | – | if `allow_user_aliases`; source on own domain only |
| Share a domain (grants, invitations) | ✓ | config flag (default off) | – | – |
| Blocked addresses | manage | view | view | – |
| Console users, audit log (all) | ✓ | – | – | – |
| Audit log (own domains) | ✓ | ✓ | ✓ | – |

Address rules, enforced in one `AddressClaims::check($principal, $source)` service called by every write path (inside the repository transaction, after `GET_LOCK`):

1. The source's domain is a domain on the server, and the principal is entitled to it.
2. The source is not blocked.
3. If the source is an **existing mailbox**, only these may proceed:
   - the mailbox's own owner (forwarding their own mailbox), or
   - a manager or admin of that domain, with an explicit `redirect_mailbox=true` flag in the request, so the UI must confirm it.
4. If the source already has aliases owned by someone else, a mailbox user is refused. A source "belongs" to a mailbox user only if all its destinations are that user.
5. Catch-all sources (`@domain`) are admin or manager only. They need the guide's `email2email` map so that existing mailboxes still receive their mail; `doctor` checks for it before the feature can be enabled.

## 7. API surface (v1)

All JSON under `/api/v1`. Every list is paginated (`?cursor=`, `?q=`, `?limit=`, max 200 per the brief's page size). Responses go through API Resources, so the `password` column can't be serialised by accident; it is also `$hidden` on the model.

```
POST   /auth/login                      {identifier, password} → session | {two_factor: true}
POST   /auth/two-factor-challenge       (Fortify)   /auth/passkey/{options,login}
POST   /auth/logout   /auth/logout-all
POST   /auth/confirm-password           step-up (Fortify)
POST   /auth/forgot-password  /auth/reset-password   (Fortify)
GET    /auth/invitations/{token}        POST /auth/invitations/{token}/accept
GET    /me                              PATCH /me    PUT /me/password
POST|DELETE /me/two-factor  …           /me/passkeys …   /me/recovery-codes

GET    /dashboard                       counts, near-quota mailboxes, recent activity
GET    /search?q=                       domains, mailboxes and forwarding across allowed domains

GET    /domains                         POST /domains              (admin)
GET    /domains/{domain}                DELETE /domains/{domain}   (admin, step-up, typed confirmation)
GET|PATCH /domains/{domain}/settings
GET    /domains/{domain}/dns-check      (phase 4)
GET|POST /domains/{domain}/dkim         (phase 4)

GET    /domains/{domain}/mailboxes      POST …
GET    /domains/{domain}/mailboxes/{local}   PATCH (quota, display name) DELETE
POST   /domains/{domain}/mailboxes/{local}/password       {generate:true} → password shown once
POST   /domains/{domain}/mailboxes/{local}/disable | enable
GET    /domains/{domain}/mailboxes/{local}/usage          doveadm quota

GET    /domains/{domain}/forwarding     grouped by source: [{source, targets:[{address, kind: local|external}]}]
PUT    /domains/{domain}/forwarding/{local}   {targets:[…], keep_copy:bool}   full replace, one transaction
DELETE /domains/{domain}/forwarding/{local}

GET|POST|DELETE /domains/{domain}/access            grants and invitations
GET    /addresses                       the "All addresses" view
GET|POST|DELETE /blocked-addresses
GET|POST|PATCH|DELETE /users            (admin)
GET    /audit-log?domain=&actor=&action=&from=&to=

# Mailbox portal (mailbox guard only)
GET    /portal/me                       address, quota, usage, forwards, aliases, permissions
PUT    /portal/password
PUT    /portal/forwarding               {targets, keep_copy}
GET|POST|DELETE /portal/aliases
GET    /portal/client-settings          from config: IMAP/SMTP hosts and ports

GET    /up                              Laravel health route, no details
```

Notes:

- **Phase 1 adds** `GET /domains/names`, `GET /domains/{domain}/addresses/check?local=`, `POST /domains/{domain}/mailboxes/bulk`, `/me/pinned-domains` and `POST /auth/setup`, and uses page numbers instead of cursors. Details are in `docs/superpowers/specs/2026-10-10-phase-1-admin-console-design.md`.
- **Forwarding is a full-replace `PUT` per source.** The brief merges aliases and redirects into one "forwarding" concept with several targets. Replacing the whole row set for a source in one transaction, under the source lock, avoids partial states. `keep_copy` maps to the `me → me` row.
- **A generated password is returned exactly once,** in that response, with `Cache-Control: no-store`. It is never logged. `password`, `password_confirmation`, `current_password` and `targets` are not put into the session on validation failure (`$dontFlash`).
- **Every mutating endpoint writes the audit log in the same transaction** as the change, through an `AuditLog::record()` service that the repositories call. Model observers are not used, because they would miss query-builder writes and run outside the transaction boundary.

## 8. Integrations and tooling

### Dovecot (doveadm HTTP API)

Installing the app adds `/etc/dovecot/conf.d/99-emailsquire.conf`. This was verified on Dovecot 2.4.1 (Debian Trixie) in the phase 0 prototype:

```
doveadm_api_key = <random, also in .env as DOVEADM_API_KEY>
service doveadm {
  inet_listener http {
    listen = 127.0.0.1
    port = 8080
  }
}
service auth {
  unix_listener emailsquire-auth {
    mode = 0600
    user = emailsquire
  }
}
```

**Doveadm HTTP API.**

- Requests are `POST http://127.0.0.1:8080/doveadm/v1` with header `Authorization: X-Dovecot-API <base64(key)>` and body `[[command, {params}, tag]]`. A missing or wrong key returns HTTP 401.
- The `DoveadmClient` service wraps Laravel's HTTP client with a 3 s timeout.

| Use | Request | Verified reply |
|---|---|---|
| Storage used | `["quotaGet", {"user": "x@d"}]` | `[["doveadmResponse", [{"root":"User quota","type":"STORAGE","value":"1","limit":"1000","percent":"0"}, {…"type":"MESSAGE","limit":"-"…}], tag]]`. Values are KiB strings; `-` means no limit. |
| Sign out after password change, disable or delete | `["kick", {"mask": ["x@d"]}]` | `[["doveadmResponse", [{"count":"0"}], tag]]` |
| Maildir path before deletion | `["user", {"userMask": ["x@d"], "field": "home"}]` | `[["doveadmResponse", {"x@d": {"home": "/var/vmail/d/x"}}, tag]]`. `field` must be a string: an array dropped the connection. |
| Unknown user (any command) | | `[["error", {"type":"exitCode","exitCode":67}, tag]]` |

`authTest` is **not** available over the HTTP API (`unknownMethod`). That is why password checks use the auth socket instead.

**Auth socket.**

- The socket is the Dovecot auth client protocol, the one Postfix uses for SMTP AUTH. A `DovecotAuthClient` service runs this exchange:
  - it sends `VERSION\t1\t2` and `CPID\t<pid>`,
  - it reads the handshake up to `DONE`,
  - it sends `AUTH\t1\tPLAIN\tservice=emailsquire\tnologin\trip=<client IP>\tresp=<base64(\0user\0password)>`,
  - `rip=` carries the sign-in request's client IP, validated with `FILTER_VALIDATE_IP` before it goes on the wire. Without it, Dovecot puts every check from the app into one shared auth-penalty bucket. On 2.4.1, each wrong password then delays the next check for everyone: 2 s, 5.5 s, 10 s, 17 s. Portal sign-in times out for all users after four failures, and Dovecot's log has no IP for fail2ban. Measured in the phase 0 mail-truth stack.
  - Dovecot answers `OK\t1\t…` or `FAIL\t1\t…`.
- Only the `emailsquire` OS user can open the socket; other users get `EACCES`.

If doveadm is unreachable, the app still works and usage shows as "unavailable". If the auth socket is unreachable, portal sign-in fails closed.

### Postfix

No integration beyond the tables. Outgoing invitation and reset emails go through Laravel Mail over SMTP to `localhost:25`, queued, from a configured `MAIL_FROM_ADDRESS`. Optionally the sender can be a real mailbox, so DKIM and SPF pass.

### rspamd DKIM (phase 4)

Per domain: the `dkim` helper (plain PHP as `_rspamd`) calls `rspamadm dkim_keygen` through `proc_open()` with an argument array, so no shell is involved. It writes the key to the path pattern from the guide's `dkim_signing.conf` and updates the selector map. rspamd reloads maps on change. The API returns the DNS TXT record to publish. The DNS check shows whether it is live.

### DNS health check (phase 4)

A queued job per domain, rechecked by the scheduler every 6 h and on demand. It checks:

- MX points at this host,
- SPF includes it,
- the DKIM TXT matches the key on disk,
- DMARC exists,
- the server's PTR matches its hostname,
- optionally MTA-STS and autoconfig.

It uses `dns_get_record()` against the system resolver, and results are cached for 10 min.

### `php artisan emailsquire:doctor`

A CLI that is also shown on an admin page. It is how the app copes with servers that drifted from the guide.

- Tables, columns and lengths match Trixie.
- Restored Bookworm leftovers: a missing `UNIQUE KEY (name)`, with the `ALTER` to add it; and `{SHA256-CRYPT}` / `{SHA512-CRYPT}` hashes, counted. Those still work, and they upgrade when the user next changes their password.
- Data anomalies:
  - mixed-case or Unicode addresses
  - email and `domain_id` mismatches
  - aliases whose source is an existing mailbox (listed for review, not "fixed")
  - duplicate alias pairs
  - app rows pointing at missing domains
- Hash schemes Dovecot 2.4 rejects by default (`PLAIN-MD5` etc.).
- The doveadm API and the auth socket `/run/dovecot/emailsquire-auth` are reachable; the mail root exists and is owned by `vmail`; the trash directory is on the same filesystem; the rspamd DKIM paths are writable by `_rspamd`.
- Whether the `email2email` map is in `virtual_alias_maps`, which catch-all support needs.
- Which `smtpd_sender_login_maps` is active (read from `/etc/postfix/main.cf`), and whether its `.cf` file still holds the installer's `MAILSERVER-PASSWORD-HERE` placeholder. The installer's `going_live` step doesn't replace the placeholder in `mariadb-sender-login-maps.cf`.
- PHP and runtime checks:
  - `disable_functions` is set in the app's FPM `php.ini`.
  - `APP_DEBUG=false` and `APP_ENV=production`.
  - The `config:cache` and `route:cache` files are present.

### Moving from ISPmail Admin

The tables are the same, so no data migration is needed. `sudo php artisan emailsquire:import-ima --database=migrator [--drop]` copies `blacklist_email` into `blocked_addresses` (HTML entities in the old rows are decoded) and, with `--drop`, drops the old table. Run `doctor` first: rows written by earlier tools may sit under the wrong `domain_id`, and `doctor` lists them.

## 9. Hardening and operations

**Layout:**

- Releases go in `/opt/emailsquire/releases/<version>`, with `current` as a symlink to the active one.
- `storage/` and `bootstrap/cache/` sit outside the release, owned by `emailsquire`.
- `.env` lives at `/etc/emailsquire/.env`, mode `0640 root:emailsquire`, and is symlinked into the release.
- The code is owned by root and read-only to `emailsquire`. A compromised PHP process can't rewrite the app.
- The release tree is world-readable (directories `0755`, files `0644`): the maildir units run `helpers/maildir.php` from it as `vmail`, so `vmail` must be able to traverse the tree and read `helpers/`. `.env` stays `0640 root:emailsquire`, so `vmail` still cannot read it.

**Build in CI, not on the server.** CI runs `composer install --no-dev --classmap-authoritative`, `npm ci && npm run build`, and the `config:cache`, `route:cache` and `event:cache` steps (the caches are rebuilt on the host after linking `.env`). It ships a tarball or `.deb`. The mail host never runs Composer or Node.

**systemd units:** `emailsquire-fpm.service`, `emailsquire-queue.service`, `emailsquire-schedule.{service,timer}`, `emailsquire-maildir.{service,timer}`, `emailsquire-dkim.{service,timer}`.

```ini
# emailsquire-fpm.service (queue and schedule units use the same [Service] hardening)
[Service]
User=emailsquire
ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/emailsquire/php-fpm.conf -c /etc/emailsquire/php.ini
RuntimeDirectory=emailsquire
ReadWritePaths=/var/lib/emailsquire/storage
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectControlGroups=yes
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
RestrictNamespaces=yes
LockPersonality=yes
SystemCallArchitectures=native
CapabilityBoundingSet=
# Not MemoryDenyWriteExecute: PHP's PCRE JIT needs W+X memory. Set pcre.jit=0 if you want it.
# maildir helper: User=vmail,   ReadWritePaths=/var/vmail, ExecStart=/usr/bin/php /opt/emailsquire/current/helpers/maildir.php run (purge unit: … purge)
# dkim helper:    User=_rspamd, ReadWritePaths=/var/lib/rspamd/dkim /etc/rspamd/dkim_selectors.map
```

**`/etc/emailsquire/php.ini` (FPM only):**

```ini
disable_functions = exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec
expose_php = Off
zend.exception_ignore_args = On
allow_url_include = Off
open_basedir = /opt/emailsquire:/var/lib/emailsquire:/etc/emailsquire:/tmp:/run/dovecot/emailsquire-auth
session.use_strict_mode = 1
opcache.validate_timestamps = 0
```

**HTTP:**

- A separate vhost (`admin.example.org`), so the admin app gets its own origin, separate from Roundcube's. Apache serves `public/` only.
- A `SecurityHeaders` middleware sets the headers:
  - `Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-…'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'`, with the nonce from `Vite::useCspNonce()`,
  - HSTS, `X-Content-Type-Options`, `Referrer-Policy: no-referrer` and `Permissions-Policy`.
- Request body limit is 64 KiB, set both by the Apache `LimitRequestBody` directive and by `post_max_size`.
- Optional Apache `Require ip` allowlist for `/api/v1/*` except `/portal` and `/auth`, if console users have fixed IPs or a VPN.

**Code rules:**

- No raw SQL string building. Query builder bindings only, and `DB::raw` is allowed only in the repository layer, with a lint rule for it.
- No `exec` in the app; FPM `disable_functions` enforces it.
- The API never returns HTML. Output escaping is the frontend's job: React escapes by default, and a lint rule bans `dangerouslySetInnerHTML`.
- `APP_DEBUG=false` in production is checked by `doctor` and by a boot-time guard that refuses to serve if it is on.

**Backups:** the `emailsquire` schema sits next to `mailserver` and goes into the same `mysqldump`. Back up `/etc/emailsquire/.env` separately: without `APP_KEY`, the encrypted TOTP secrets are lost. Key rotation uses `APP_PREVIOUS_KEYS`.

**Install steps:** create the empty `emailsquire` database → `sudo php artisan migrate --database=migrator` → `sudo php artisan emailsquire:grants --database=migrator`, then review its output and run it as MariaDB root → `sudo -u emailsquire php artisan emailsquire:doctor --require-server` → `php artisan emailsquire:create-admin` → `systemctl enable --now emailsquire-schedule.timer emailsquire-maildir.timer emailsquire-maildir-purge.timer`. The unit files are in `deploy/systemd/`; put `--trash-dir` and `--retention-days` on the unit's `ExecStart` when they differ from the app config. Keep the trash inside the mail root, which is the default. Archiving is a `rename()`, and systemd bind-mounts every `ReadWritePaths=` entry separately, so a trash dir added as a second entry fails with `EXDEV` ("Invalid cross-device link") even on the same filesystem. If it must live elsewhere, give both maildir units one `ReadWritePaths=` entry that is a common parent of the mail root and the trash dir. `run` and `purge` share the lock file `<mail-root>/.emailsquire-maildir.lock`, so they never overlap. Re-run `grants` after every upgrade that adds tables. It refuses to print anything when it can't see the migrated tables. Run artisan as `emailsquire` except for `migrate --database=migrator` and `grants`; if root ran other commands, `chown -R emailsquire: /var/lib/emailsquire/storage`. Run the maildir helper by hand only as `vmail`: `sudo -u vmail php /opt/emailsquire/current/helpers/maildir.php run` (or `runuser -u vmail -- php …`). It refuses any user but the mail root's owner, root included, because root-owned files under `/var/vmail` would break every later timer run.

## 10. Optional schema extension (not in v1)

Only if you want "stop receiving mail" or "send-only mailbox": add `enabled TINYINT NOT NULL DEFAULT 1` and `sendonly TINYINT NOT NULL DEFAULT 0` to `virtual_users`, and add `AND enabled = 1` to the mailbox map and Dovecot queries. The app would check for these columns at boot and switch features on only if they exist. This is a documented change the server admin applies on purpose. The app never does it itself, and Laravel migrations never touch `mailserver`.

## 11. Testing

All database tests run against **MariaDB, not SQLite**. SQLite has no schema-qualified tables, no `GET_LOCK` and different collation, so the tests would pass while production breaks. CI uses a MariaDB service container loaded with the exact Trixie schema and a "restored from Bookworm" variant (no `UNIQUE KEY`, `SHA512-CRYPT` hashes).

| Layer | What | Tooling |
|---|---|---|
| Unit | `AddressNormalizer` (IDN, case, length), `AddressClaims` rules, quota formatting, hash format | Pest, plus a dataset of hostile inputs: Unicode confusables, `..`, `%` and `/` in local parts, overlong labels, trailing dots |
| Policy | The full section 6 matrix as a Pest dataset: every role × action × own-domain or other-domain. Cross-domain and cross-mailbox access attempts are explicit regression cases. | Pest feature tests against the API |
| Architecture | Only repositories write guide tables; every controller authorises; no `DB::raw` outside repositories; no `env()` outside config | Pest `arch()` |
| Mail truth (end-to-end) | A compose stack with Postfix and Dovecot 2.4 configured like the guide on `debian:trixie`. After API calls, assert what the mail server actually sees: `postmap -q` against the guide's `.cf` files, the auth socket, the doveadm API, and real delivery with `swaks` for forwarding, keep copy, aliases and disabled mailboxes. This is the test that proves "Postfix doesn't notice the app". | Docker Compose in CI |
| Helpers | Maildir archive with crafted bad inputs: symlinked paths, `..` in local parts, the address re-created before the task runs, a task whose mailbox still exists | Pest against a temp-dir tree |
| Security | CSRF (missing token, foreign `Origin`), session fixation, rate limits, step-up enforcement, 404-not-403, required 2FA for admins, a secret-redaction test over every log and audit line | Pest, plus a ZAP baseline scan in CI |
| Static analysis | Larastan at max level; Psalm taint analysis on `app/` for SQL, shell and path sinks | CI |
| Supply chain | `composer audit` and `npm audit` in CI, Dependabot or Renovate, an SBOM per release | CI |

## 12. Phases

These line up with the brief's phases, plus a foundation phase in front.

| Phase | Backend scope | Done when |
|---|---|---|
| **0. Foundations** | Laravel skeleton, single connection with schema-qualified guide models, migrations for the app schema, `doctor`, the MariaDB test fixtures (Trixie and restored), the mail-truth compose stack, CI with static analysis and audits, the build-artifact pipeline | `doctor` passes on a fresh Trixie stack, and CI is green |
| **1. Parity, admin only** | Server admin sign-in (Fortify, password only; TOTP moves to phase 2), sessions, CSRF, rate limits; domains, mailboxes, forwarding (grouped, keep copy) and blocked addresses; disable and enable; quota with Trixie semantics; dashboard, search and "All addresses"; audit log; maildir helper with trash and purge; doveadm usage and kick; `import-ima` | Everything ISPmail Admin does in `ADM` mode, with the section 6 authorization rules proven by tests |
| **2. Sharing** | TOTP 2FA with recovery codes, console users, domain grants (manager or viewer), invitations, password reset, passkeys, required 2FA for admins, step-up, per-domain limits, activity log filters, queued emails | The brief's screens 10–15 are fully backed |
| **3. Mailbox portal** | `mailbox` guard with Dovecot-verified sign-in, password change, own forwarding and aliases under domain and mailbox toggles, usage, client settings | Brief screens 16–19, and the policy matrix's mailbox column passes |
| **4. Mail hygiene** | DKIM helper and records, DNS health check, catch-all (gated on `doctor`), optional schema extension support | Per-domain DNS page is green on a correctly set-up domain |

Out of scope for now: autoreply. Roundcube already manages Sieve vacation through ManageSieve. Doing it in the portal would mean speaking ManageSieve with a Dovecot master user, a sizeable new trust boundary. Revisit after phase 3.

## 13. Decisions

Decided:

- **Laravel** (Oct 9, 2026).
- **Trixie only** (Oct 9, 2026). Restored Bookworm data on a Trixie server is still handled (section 1).
- **Same host:** the app runs on the mail server, which the helpers and unix-socket DB auth need.
- **No unlimited quota** (Oct 9, 2026). Every mailbox has a finite quota: `0` means the 5 GB default, and any other value is an explicit size. No Dovecot config change is needed.
- **Languages: English and Finnish** (Oct 10, 2026). English is the default and the fallback. The console uses the user's saved language, then the browser's; server messages follow the same choice. Plan: `docs/superpowers/plans/2026-10-10-phase-2a-i18n.md`; wording: `docs/i18n-glossary.md`.

Still open (from the brief):

- Can managers share domains? The plan adds a config flag, default off.
- Is a viewer role needed? The plan includes it.
- Per-domain limits: included.
- Self-service defaults: proposed password on, forwarding on, aliases off, external forwarding off.

## 14. Verified against the installer, and what's still open

workaround.org serves unrelated filler text to automated page fetches, but its installer (`https://workaround.org/ispmail.sh`, v0.5 of 2026-08-03) downloads normally. It was read, not run, on Oct 9, 2026. Verified:

- [x] Maildir layout: `mail_home = /var/vmail/<domain>/<local>` and `mail_path = ~/Maildir`. The helper archives the whole home directory, which includes Sieve scripts.
- [x] Quota: `quota_storage_size = 5G` globally; the userdb returns `NULL` for `quota = 0`.
- [x] rspamd DKIM: the key path pattern and selector map are as in section 1, and the directory is owned by `_rspamd`.
- [x] The `email2email` map exists and is added to `virtual_alias_maps` for catch-alls.
- [x] Sender login map: the guide's query does include aliases, but the installer ends up on `email2email` (section 1).
- [x] fail2ban: not set up by the installer at all (section 5.2).
- [x] Roundcube runs under mod_php.
- [x] Laravel 13 requires PHP ^8.3.

- [x] Dovecot 2.4.1: the doveadm HTTP listener and auth socket syntax, the reply shapes and the auth failure delay (section 8). The mailbox password schemes and the disabled-hash approach (section 4.3) were verified in a Docker prototype of the installer's config.

Still open:

- [ ] Whether `laragear/webauthn` (v5) is maintained and fits a JSON API. Not needed until phase 2.

Upstream issue worth reporting to <https://github.com/Signum/ispmail-workaround-org/issues>: in installer v0.5, `relaying_setup` overrides the sender login map that `postfix_config` sets, and `going_live` leaves the password placeholder in `mariadb-sender-login-maps.cf`.
