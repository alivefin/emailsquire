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
| `password_reset_tokens` | Laravel's, for forgotten-password links (the `users` broker, 30 minutes) |
| `admin_setup_tokens` | Setup links from `emailsquire:create-admin` (the `admin_setup` broker). Kept apart from reset links: a broker finds a token by email alone, and setup signs in without asking for the password. A user with confirmed two-factor still has to pass the two-factor challenge after it. |
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

Two guards share one sign-in screen and one endpoint (`POST /auth/login`). The console account is tried first; only when there is none, or its password check fails, is the address tried as a mailbox (section 5.2). An address that is both gets the console with the console password and the portal with the mail password. A session is one or the other, never both: signing in on one guard signs the other out, console routes take `auth:web` only and portal routes `auth:mailbox` only, and each answers 401 to the other kind.

### 5.1 Console users (`web` guard, Fortify)

- Passwords hashed with Argon2id (Laravel `argon2id` driver; m = 64 MiB, t = 3). Validated with `Password::min(12)->uncompromised()`. `uncompromised()` calls Have I Been Pwned (HIBP) with k-anonymity. It is an outbound call, so a config flag controls it; the default is on, since the mail host has outbound HTTPS anyway.
- **2FA (phase 2b):**
  - Fortify TOTP with confirmation and 8 single-use recovery codes. The secret and the codes are encrypted with `APP_KEY`. Window 1 (one 30 s step either side), and each code works once per secret: `TwoFactorCodeProvider` marks its period used with an atomic cache add.
  - Passkeys (`laragear/webauthn`) move to a later phase; the package is still unvetted (§13). Phase 1 signed in with a password only.
  - **Required for server admins** (`EMAILSQUIRE_REQUIRE_TWO_FACTOR`: `admins` by default, or `everyone`; `off` is for the test suite only and `doctor` fails it). Until it is on, `RequireTwoFactor` answers 403 `two_factor_required` on every authenticated route except `/me`, `/me/two-factor*`, `/me/recovery-codes`, `/me/password` and `/auth/*`, and the SPA sends the user to My profile's two-factor section.
  - Optional for domain managers, unless config requires it.
- **Onboarding and recovery:**
  - Invitations (brief 6.4) are 256-bit random tokens, stored hashed, single-use, expiring after 72 h.
  - Password reset (brief 6.5) uses Fortify's broker with a 30 min expiry. The response is the same whether or not the account exists, and requests are throttled to 5 an hour per email and 20 an hour per IP. The emailed job is encrypted on the queue (`ShouldBeEncrypted`). A reset ends every session of the user and signs nobody in; a password change ends the other sessions. Both send a security notice.
- **Bootstrap:** `php artisan emailsquire:create-admin you@example.org` prints a one-time setup link. There is no default credential, no seeder that creates users in production, and no setup page exposed to the web.

### 5.2 Mailbox users (`mailbox` guard, portal, phase 3, as built)

- **Principal:** `MailboxUserProvider` returns a read-only `MailboxPrincipal` (implements `Authenticatable`: address, domain id, quota, stored hash) built from the `virtual_users` row. `retrieveById()` re-reads the row on every request and returns `null` when it is gone or has a `disabled_mailboxes` row, so deleting or disabling a mailbox ends its portal sessions at the next request. The session also keeps the hash it signed in against (`AuthenticateSession`), so a password set elsewhere (an admin's reset, Roundcube) ends it too.
- **Sign-in routing (`SignInRouting`, before Fortify's pipeline):**
  1. A console account with that email whose password matches runs the console pipeline unchanged (two-factor, then `web`), answering `kind: 'console'`.
  2. Otherwise the address counts against `mailbox-login` (5 a minute per address and IP, 30 a minute per IP) and, when its domain is hosted here, Dovecot is asked through `PasswordChecker`, mailbox or not, so the refusal takes `auth_failure_delay` either way. Only an existing, enabled mailbox whose password Dovecot accepts signs in on `mailbox`: `{kind: 'mailbox'}`. An address on a domain not hosted here is refused without asking Dovecot (it tells nothing that MX records don't).
  3. Every refusal is Fortify's one 422 ("Email or password is wrong."). The fail2ban line says `kind=mailbox` when the address is a mailbox (enabled or not) and `kind=console` otherwise. A mailbox sign-in clears its own `mailbox-login` count, never `login`.
- **Dovecot checks the password** (`DovecotAuthClient`, the auth socket `/run/dovecot/emailsquire-auth`, `service=emailsquire`, the client's IP as `rip=`; section 8). Dovecot understands every scheme on the server (`BLF-CRYPT`, `CRYPT`, restored `SHA*-CRYPT`, all verified on 2.4.1), which matters because Roundcube's password plugin writes `{CRYPT}`; the attempt lands in Dovecot's auth log and its per-IP penalty. Dovecot answers a failure only after `auth_failure_delay` (2 s), for an unknown user and a wrong password alike (mail truth measures both, `tests/MailTruth/PortalTest.php`), so the client's timeout is 10 s and the app adds no delay. A socket that can't be reached fails closed with the same refusal.
- **The e2e stand-in:** with `APP_ENV=e2e`, and only then, `PasswordChecker` is `FakeDovecotAuthClient`, which checks `{BLF-CRYPT}` hashes with `password_verify()` and refuses to run in any other environment. `doctor`'s `runtime.test_environment` fails an `e2e` or `testing` environment on the production mail root `/var/vmail`.
- **Password change (`PUT /portal/password`):** the current password is checked by Dovecot; the new one follows the mailbox rules (12 characters to 72 bytes, strength 3 or more, HIBP when on) and is stored as `{BLF-CRYPT}` + `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`, but only if the stored hash is still the one this session signed in with. The mailbox's IMAP sessions are kicked and its other portal sessions end; this one moves to a new session id and stays signed in. 6 attempts a minute per mailbox (`mailbox-password`), wrong current passwords included.
- **Self-service switches** (per domain, `domain_settings`): `allow_user_forwarding` (default on), `allow_user_aliases` (off) and `allow_external_forwarding` (off). Server admins and the domain's managers set them (`PATCH /domains/{d}/self-service`, audited `domain.self_service_changed`); viewers read them. While a switch is off the portal's writes for it answer 403 `self_service_disabled` (checked before the body and again under the domain row's lock) and the list stays readable. Per-mailbox overrides (`mailbox_settings`) are not used yet.
- **Owner limits:** at most 10 forwarding targets; targets on any domain of this server are allowed while forwarding is on, others need external forwarding (422 `external_forwarding_disabled`). At most 20 aliases, each a local part on the owner's own domain that delivers only to their mailbox. `AddressClaims` judges every write with the owner as principal, plus: no local part from `emailsquire.owner_reserved_locals` (postmaster, abuse, hostmaster, webmaster, admin, root, …), and no alias while the domain has a catch-all that doesn't go only to this mailbox (the alias would take mail the catch-all now gets). Every refusal of an address is one answer, 422 `address_unavailable`, whatever the reason. Portal writes count against `portal-writes`, 30 a minute per mailbox.
- **Audit:** portal writes are audited with `actor_type = mailbox` and the address as actor: `portal.password_changed`, `portal.forwarding_changed`, `portal.alias_created`, `portal.alias_deleted` (group `mailboxes`). The activity log shows the address.
- **Language:** the portal has no `users` row, so it uses the language saved in the browser, then the browser's, then English; the portal header has a switch.
- **Help:** the domain's active managers (name and email); with none, `emailsquire.support_contact` (`EMAILSQUIRE_SUPPORT_CONTACT`, free text); with neither, a generic sentence. Server admins are never listed. There is no email reset for mailbox passwords (brief 6.5): sign-in and Help say to ask the domain's manager.

### 5.3 Sessions and request protection

- **Cookie:** `SESSION_COOKIE=__Host-emailsquire`, `SESSION_SECURE_COOKIE=true`, `same_site=strict`, `http_only=true`, no domain, path `/`. Session data stays server-side (`database` driver), so sessions can be revoked ("sign out everywhere" in brief 6.8).
- **Lifetime:** `SESSION_LIFETIME=30` minutes idle for both guards. A small middleware adds an absolute limit: 12 h for console users, 2 h for the portal. The session ID rotates on sign-in, sign-out and any role change (`session()->regenerate()`).
- **Step-up re-authentication** with the `step-up` middleware (`RequireStepUp`): `POST /auth/confirm-password` with the password, or a TOTP code once 2FA is on, opens a 300 s window; outside it the route answers 423 `step_up_required`, and the SPA asks in a dialog and retries the request once. Required for: deleting a domain, turning 2FA on or off, viewing or regenerating recovery codes, and (phase 2c) changing roles or grants and deleting a console user.
- **CSRF:** Laravel's `ValidateCsrfToken`, with the SPA sending `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie. On top of that, `SameSite=Strict` and an `EnsureSameOrigin` middleware that rejects state-changing requests whose `Origin` (or `Sec-Fetch-Site`) is not same-origin.
- **Rate limits:** `login` (5 a minute per address and IP, counting every attempt; a finished sign-in, with or without the two-factor step, clears it), `two-factor` (5 a minute per pending sign-in and IP), `step-up` (6 password or code guesses a minute per user, shared with the password change), `logout-all` (5 an hour per user), `forgot-password` and `reset-password`, `mailbox-login` (5 a minute per address and IP and 30 per IP, for sign-ins the console account did not take), `mailbox-password` (6 a minute per mailbox), `portal-writes` (30 a minute per mailbox), and `throttle:api` on writes. All use the database cache store. Every failed sign-in is logged as one line in a fixed format, and a fail2ban filter for it ships with the app.

## 6. Authorization

Laravel Policies (`DomainPolicy`, `MailboxPolicy`, `ForwardingPolicy`, `BlockedAddressPolicy`, `UserPolicy`) are the only place role logic lives. Every controller action calls `$this->authorize()`, and an architecture test enforces that.

- The resource is always **loaded from the database by route model binding**, and its domain comes from the stored row, never from the request.
- **Scoped queries:**
  - `DomainAccess` is the only source of a role (`admin`, `manager`, `viewer` or none). It is memoised per request only, so a grant removed mid-session is a 404 on the user's very next request.
  - Every list query takes a `DomainScope` (`DomainAccess::scope($user)`: every domain for a server admin, the granted ones otherwise) as its first parameter: domains, names, addresses, search, the dashboard, blocked addresses, pinned domains.
  - The dashboard's recent activity matches audit rows by domain name (the audit keeps the name, not an id). A domain deleted and created again under the same name therefore shows the old one's history to whoever has the new one. That is intended for now.
  - Route model binding for nested routes uses `->scopeBindings()`, so `/domains/a.org/mailboxes/x` can never resolve a mailbox from `b.org`.
- **Readable URLs:** routes bind by natural key (`/api/v1/domains/{domain:name}/mailboxes/{mailbox:local}`), which the brief wants for bookmarkable pages.
- **404 versus 403:** out of scope is `404`, through `Response::denyAsNotFound()` in policies, so nobody can probe what exists: a domain without a role on it (and everything under it), a grant or invitation id of another domain, and every Users route for anyone but a server admin. It comes from the `can:` middleware, before step-up, so it is never a `423`. In scope but not allowed by the role is `403`: a viewer writing, a manager creating or deleting a domain or changing its limits, a manager sharing while `managers_can_share` is off.
- **Deactivated users** can't sign in (the wrong-password answer), keep their grants with no role, and a session they still hold answers `401` on its next request (`EnsureActiveUser`).

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
POST   /auth/login                      {email, password} → me | {two_factor: true}
POST   /auth/setup                      {token, email, password, password_confirmation} → me | {two_factor: true}
POST   /auth/two-factor-challenge       {code} | {recovery_code} → me
POST   /auth/logout   /auth/logout-all  (logout-all ends the user's other sessions)
POST   /auth/confirm-password           {password} | {code} → 204, opens the 300 s step-up window
POST   /auth/forgot-password {email}    POST /auth/reset-password {token, email, password, password_confirmation}
POST   /auth/invitations/lookup         {token} → the invitation and its state (phase 2c)
POST   /auth/invitations/accept         {token} signed in as the invited email | {token, name, password, password_confirmation} → me
GET    /me                              PATCH /me {name, locale}   PUT /me/password {current_password, password, password_confirmation}
POST   /me/two-factor                   start enrolment (step-up)   DELETE /me/two-factor (step-up)
GET    /me/two-factor/setup             secret and QR SVG of the unconfirmed setup (step-up)
POST   /me/two-factor/confirm           {code} → 8 recovery codes (step-up)
GET|POST /me/recovery-codes             show or regenerate (step-up)

GET    /dashboard                       counts, near-quota mailboxes, recent activity
GET    /search?q=                       domains, mailboxes and forwarding across allowed domains

GET    /domains                         POST /domains              (admin)
GET    /domains/{domain}                DELETE /domains/{domain}   (admin, step-up, typed confirmation)
GET|PATCH /domains/{domain}/settings  {max_mailboxes, max_quota_bytes_total} (any role reads, admin changes)
GET    /domains/{domain}/dns-check      (phase 4)
GET|POST /domains/{domain}/dkim         (phase 4)

GET    /domains/{domain}/mailboxes      POST …
GET    /domains/{domain}/mailboxes/{local}   PATCH (quota, display name) DELETE
POST   /domains/{domain}/mailboxes/{local}/password       {generate:true} → password shown once
POST   /domains/{domain}/mailboxes/{local}/disable | enable
GET    /domains/{domain}/mailboxes/{local}/usage          doveadm quota
POST   /domains/{domain}/mailboxes/{local}/welcome        {to, locale} → 202, setup instructions, never the password (phase 2d)

GET    /domains/{domain}/forwarding     grouped by source: [{source, targets:[{address, kind: local|external}]}]
PUT    /domains/{domain}/forwarding/{local}   {targets:[…], keep_copy:bool}   full replace, one transaction
DELETE /domains/{domain}/forwarding/{local}

GET    /domains/{domain}/access         people with a grant, and pending invitations (any role)
PATCH  /domains/{domain}/access/{user}  {role}   DELETE … (share, step-up; anyone may remove their own grant)
POST   /domains/{domain}/access/invitations   {email, role, note?, locale, name?} (share, step-up)
POST   /domains/{domain}/access/invitations/{invitation}/resend   DELETE /domains/{domain}/access/invitations/{invitation}   (share, step-up)
GET    /addresses                       the "All addresses" view
GET|POST|DELETE /blocked-addresses
GET    /users/invitations               every invitation not accepted or revoked, server and domain, newest first (admin)
POST   /users/invitations               {email, role, domain?, note?, locale, name?}   (admin, step-up)
POST   /users/invitations/{invitation}/resend   DELETE /users/invitations/{invitation}   (admin, step-up)
GET    /users                           GET /users/{user}   (admin)
PATCH  /users/{user}                    {name, email, is_server_admin, status} (admin; step-up for all but the name)
DELETE /users/{user}                    (admin, step-up)
POST   /users/{user}/grants {domain, role}   DELETE /users/{user}/grants/{domain}   (admin, step-up)
POST   /users/{user}/two-factor/reset   (admin, step-up)
GET    /audit-log?domain=&actor=&action_group=&from=&to=&before_id=&page=&per_page=   (any role, filtered by AuditVisibility)
GET    /audit-log/actors                who acted in the rows the caller may read (the person filter)

GET|PATCH /domains/{domain}/self-service  {forwarding, aliases, external_forwarding} (any role reads, admin or manager changes)

# Mailbox portal (mailbox guard only; a console session gets 401, as a portal session does on every route above)
GET    /portal/me                       address, domain, quota_bytes, used_bytes|null, self_service switches, managers, support_contact, client_settings
PUT    /portal/password                 {current_password, password, password_confirmation} → 204
GET    /portal/forwarding               {targets, keep_copy}
PUT    /portal/forwarding               {targets, keep_copy} → the saved forwarding; empty targets turns it off
GET    /portal/aliases                  the sources that deliver only to this mailbox
POST   /portal/aliases {local} → 201    DELETE /portal/aliases/{local} → 204

GET    /up                              Laravel health route, no details
```

Notes:

- **Phase 3 adds** the self-service and portal routes above (spec: `docs/superpowers/specs/2026-10-11-phase-3-mailbox-portal-design.md`). `POST /auth/login` answers `{kind: 'mailbox'}` for a portal sign-in and adds `kind: 'console'` to the console answer. `POST /auth/logout` signs either kind out. Portal writes need the domain's switch: 403 `self_service_disabled` before the body is read. Problem codes: 422 `address_unavailable` (one answer for every taken, blocked, reserved or shadowed address), `external_forwarding_disabled`, `too_many_targets`, `too_many_aliases`; 503 `password_check_unavailable` when Dovecot's auth socket can't be reached during a password change. Throttles: `mailbox-login`, `mailbox-password`, `portal-writes` (section 5.3).
- **Phase 2d adds** the audit-log and welcome routes above (plan: `docs/superpowers/plans/2026-10-11-phase-2d-activity-and-welcome.md`).
  - `GET /audit-log`: rows the caller may read (section 13), ordered `occurred_at desc, id desc`. `domain` is a domain name in ASCII or Unicode; one that is unknown or outside the caller's domains gives an empty page, not a 404. `actor` is a user id, `cli` or `system`. `action_group` is one of `mailboxes`, `forwarding`, `domains`, `blocked`, `access`, `account` (`AuditActions::GROUPS`). `from` (inclusive) and `to` (exclusive) are ISO 8601 date-times with an offset. `before_id` pins the later pages to the first page's newest row, so rows written meanwhile don't shift them.
  - `POST …/welcome`: managers and admins (`can:update,mailbox`; a viewer gets 403). `to` is one address (`email:rfc`, at most 254 characters, no display name, list or header characters: 422) and `locale` is `en` or `fi`. The mail is queued and audited `mailbox.welcome_sent` with `changes: {to}`. Only mails that were queued count against the limit of 10 an hour per user; the 11th gets 429.
- **Phase 2c adds** the access, invitation, Users and settings routes above (plan: `docs/superpowers/plans/2026-10-10-phase-2c-sharing.md`). The invitation link is `{APP_URL}/invite#{token}`: the token rides in the fragment, which browsers never send, and the API takes it only in POST bodies. An inviter who is deactivated or loses the server-admin role, or a manager who loses the manager role on a domain, has their open invitations (to that domain) revoked for good, each audited `invitation.revoked`; an invitation that makes a signed-in user a server admin ends their other sessions, as a role change on the Users screen does. Throttles: `invitations` 30 an hour per user (create and resend), `invitation-lookup` 30 a minute and `invitation-accept` 10 a minute per IP. Problem codes: 409 `already_has_access`, `user_deactivated`, `invitation_closed`, `cannot_change_self`, `last_server_admin`; 410 `invitation_invalid` (with its `state`) for a closed invitation link; 422 `domain_limit`.
- **Phase 2b adds** the two-factor, step-up, password and `/me` routes above (spec: `docs/superpowers/specs/2026-10-10-phase-2-sharing-design.md`, section 2b). Problem codes: 423 `step_up_required`, 403 `two_factor_required`. Passkey routes come later.
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

No integration beyond the tables. Outgoing invitation and reset emails go through Laravel Mail over SMTP to `localhost:25`, queued, from `EMAILSQUIRE_MAIL_FROM` (default `emailsquire@<hostname>`). Optionally the sender can be a real mailbox, so DKIM and SPF pass.

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

**Mail and two-factor settings** (in `/etc/emailsquire/.env`, before the first start): `MAIL_MAILER=smtp`, `MAIL_HOST=127.0.0.1`, `MAIL_PORT=25` (the local Postfix; no auth, no TLS), `EMAILSQUIRE_MAIL_FROM` (default `emailsquire@` plus the host name) and `MAIL_FROM_NAME`; `EMAILSQUIRE_REQUIRE_TWO_FACTOR=admins` (or `everyone`). The welcome email names `EMAILSQUIRE_MAIL_HOST` (the name on the mail server's TLS certificate, such as `mail.example.org`; default this host's name) for IMAP on 993 and SMTP on 587, and links `EMAILSQUIRE_WEBMAIL_URL` when it is set (optional, http or https). `doctor` warns about a mail host without a dot or given as an IP address (`mail.host`), and about a webmail URL that is not http or https (`mail.webmail_url`). Every email is queued on the `jobs` table and sent by `emailsquire-queue.service`, so without that unit no reset link or security notice leaves the host.

**Mailbox portal settings** (phase 3): `EMAILSQUIRE_SUPPORT_CONTACT` (optional free text, such as "Example Hosting, help@example.net") is what the portal's Help names when the owner's domain has no manager. `emailsquire.owner_reserved_locals` in `config/emailsquire.php` lists the local parts owners may not take as aliases (postmaster, abuse, hostmaster, webmaster, admin, administrator, root, security, mailer-daemon, noc, ssladmin, ssl-admin, info); it is config, not `.env`. The self-service switches are per domain, in Domain settings.

**fail2ban:** the app logs every refused sign-in as one line, `emailsquire auth failure kind=<console|mailbox> user=<address> ip=<ip>`, which `deploy/fail2ban/filter.d/emailsquire.conf` matches (the `kind=` field is optional in the regex, so older lines still match). `kind=mailbox` means the address is a mailbox; the jail counts both kinds. Console sign-in typos on a hosted domain now also reach Dovecot's auth, since an address without a matching console password is tried as a mailbox: they count against Dovecot's per-IP penalty and appear in its auth log, so a Dovecot fail2ban jail counts them as well. Offices behind one NAT address share that penalty and that jail's count; allow for that in `maxretry`, or exempt the office's address with `ignoreip`.

**Account recovery:** `php artisan emailsquire:create-admin <email> --reset` prints a new setup link for a user who forgot their password. The link sets a new password and signs the user in; a user with confirmed two-factor still enters a code from the authenticator app or a recovery code after it. An admin who lost both the phone and the recovery codes needs `php artisan emailsquire:reset-two-factor <email>` first: it turns two-factor off (audited as `user.two_factor_disabled`, and the user gets the security notice), and the user sets it up again on My profile at the next sign-in.

**Mail after an outage:** the queue worker tries every email three times, 30 s and then 120 s apart. After a Postfix outage, `php artisan queue:retry all` sends again what landed in `failed_jobs`.

**Install steps:** create the empty `emailsquire` database → `sudo php artisan migrate --database=migrator` → `sudo php artisan emailsquire:grants --database=migrator`, then review its output and run it as MariaDB root → `sudo -u emailsquire php artisan emailsquire:doctor --require-server` → `php artisan emailsquire:create-admin` → `systemctl enable --now emailsquire-queue.service emailsquire-schedule.timer emailsquire-maildir.timer emailsquire-maildir-purge.timer`. The first admin then signs in and sets up two-factor on My profile before anything else opens. The unit files are in `deploy/systemd/`; put `--trash-dir` and `--retention-days` on the unit's `ExecStart` when they differ from the app config. Keep the trash inside the mail root, which is the default. Archiving is a `rename()`, and systemd bind-mounts every `ReadWritePaths=` entry separately, so a trash dir added as a second entry fails with `EXDEV` ("Invalid cross-device link") even on the same filesystem. If it must live elsewhere, give both maildir units one `ReadWritePaths=` entry that is a common parent of the mail root and the trash dir. `run` and `purge` share the lock file `<mail-root>/.emailsquire-maildir.lock`, so they never overlap. Re-run `grants` after every upgrade that adds tables. It refuses to print anything when it can't see the migrated tables. **Upgrading to phase 2b:** run `migrate --database=migrator`, then re-apply the `php artisan emailsquire:grants` output as MariaDB root (2b adds the `admin_setup_tokens` table), add the mail settings above, `systemctl enable --now emailsquire-queue.service` and restart it after every release (`systemctl restart emailsquire-queue.service`, since a running worker keeps the old code). Admins without two-factor are sent to set it up at their next sign-in. **Upgrading to phase 2c:** run `sudo php artisan migrate --database=migrator` (it adds the note, language and name columns to `invitations`) and restart the queue worker, which sends the invitation mails. The `emailsquire:grants` output is unchanged, so there is nothing to re-apply. Managers may share their domains only with `EMAILSQUIRE_MANAGERS_CAN_SHARE=true` in `.env` (rebuild `config:cache` after changing it); it is off by default, and then only server admins invite, change roles and remove access. **Upgrading to phase 2d:** run `sudo php artisan migrate --database=migrator` (it adds the `audit_log` time index the activity log reads by), set `EMAILSQUIRE_MAIL_HOST` and, if you have webmail, `EMAILSQUIRE_WEBMAIL_URL` in `.env` (rebuild `config:cache`), and restart the queue worker, which sends the welcome emails. The `emailsquire:grants` output is unchanged. **Upgrading to phase 3:** run `sudo php artisan migrate --database=migrator` **before the new code serves requests**: the new session handler writes `sessions.mailbox_address` on every request, so new code against the old table fails every request until the migration has run. Then rebuild `config:cache` with `EMAILSQUIRE_SUPPORT_CONTACT` if you want one, and check the self-service switches in each domain's settings (forwarding on, aliases and external forwarding off by default). The `emailsquire:grants` output is unchanged. Run artisan as `emailsquire` except for `migrate --database=migrator` and `grants`; if root ran other commands, `chown -R emailsquire: /var/lib/emailsquire/storage`. Run the maildir helper by hand only as `vmail`: `sudo -u vmail php /opt/emailsquire/current/helpers/maildir.php run` (or `runuser -u vmail -- php …`). It refuses any user but the mail root's owner, root included, because root-owned files under `/var/vmail` would break every later timer run.

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
| **3. Mailbox portal** (done, Oct 11, 2026) | `mailbox` guard with Dovecot-verified sign-in on the shared screen, password change, own forwarding and aliases under the domain's switches (per-mailbox toggles not used), usage, client settings, help and contact, English and Finnish | Brief screens 16–19, and the policy matrix's mailbox column passes. Pest, mail truth (real Dovecot sign-in, IMAP after a password change, alias delivery) and Playwright (desktop and phone, layout at tablet, one Finnish run) cover it |
| **4. Mail hygiene** | DKIM helper and records, DNS health check, catch-all (gated on `doctor`), optional schema extension support | Per-domain DNS page is green on a correctly set-up domain |

Out of scope for now: autoreply. Roundcube already manages Sieve vacation through ManageSieve. Doing it in the portal would mean speaking ManageSieve with a Dovecot master user, a sizeable new trust boundary. Revisit after phase 3.

## 13. Decisions

Decided:

- **Laravel** (Oct 9, 2026).
- **Trixie only** (Oct 9, 2026). Restored Bookworm data on a Trixie server is still handled (section 1).
- **Same host:** the app runs on the mail server, which the helpers and unix-socket DB auth need.
- **No unlimited quota** (Oct 9, 2026). Every mailbox has a finite quota: `0` means the 5 GB default, and any other value is an explicit size. No Dovecot config change is needed.
- **Two-factor: TOTP now, passkeys later** (Oct 10, 2026). Phase 2b ships Fortify TOTP with 8 recovery codes; passkeys (`laragear/webauthn`, still unvetted) move to a later phase. 2FA is required for server admins (`emailsquire.require_two_factor`: `admins` by default, or `everyone`) and optional for others. Dangerous actions need a step-up confirmation within 300 s.
- **Email delivery** (Oct 10, 2026): Laravel mail over SMTP to the local Postfix (`127.0.0.1:25`), every email queued on the `jobs` table and sent by `emailsquire-queue.service`.
- **Languages: English and Finnish** (Oct 10, 2026). English is the default and the fallback. The console uses the user's saved language, then the browser's; server messages follow the same choice. Plan: `docs/superpowers/plans/2026-10-10-phase-2a-i18n.md`; wording: `docs/i18n-glossary.md`.

- **Managers share only with the flag** (Oct 10, 2026). A manager may invite to, change roles on and remove people from their domain only while `emailsquire.managers_can_share` is on; the default is off.
- **Viewer role** (Oct 10, 2026). Read-only per domain: mailboxes, forwarding and who has access.
- **Managers can't add domains** (Oct 10, 2026). Creating and deleting domains stays with server admins.
- **Per-domain limits apply to everyone** (Oct 10, 2026), server admins included: at most `max_mailboxes` mailboxes and `max_quota_bytes_total` of quota. Only a server admin changes them; existing mailboxes stay when a limit is lowered.

- **Welcome email: settings only, never the password** (Oct 10, 2026; phase-2 spec decision 2). It sends the address (which is the username), IMAP and SMTP with their ports and security, and the webmail link to an address the sender types, in the language they pick. The password reaches the user some other way.
- **Who reads the audit log** (Oct 11, 2026; phase 2d). Server admins read every row. Anyone else reads a row only when its `domain_name` names a domain they have a role on and its action is not admin-only. Admin-only are `admin.*`, `maildir.*` and every `user.*` except `user.invited`; a server-admin invitation has no domain, so only admins see it. Rows match domains by name, so a domain created again under an old name shows the old history. One rule (`AuditVisibility`) serves `/audit-log`, `/audit-log/actors` and the dashboard. Nothing deletes audit rows; the app's DB user has only `INSERT, SELECT` on `audit_log`.

- **Mailbox portal** (Oct 11, 2026; phase 3, spec `docs/superpowers/specs/2026-10-11-phase-3-mailbox-portal-design.md`):
  1. One sign-in screen: the console account is tried first, then the mailbox through Dovecot.
  2. A session is a console session or a portal session, never both.
  3. Idle limit 30 min for both; absolute 2 h for the portal, 12 h for the console.
  4. Server admins and the domain's managers set the self-service switches; viewers see them.
  5. Defaults: forwarding on, aliases off, external forwarding off (the `domain_settings` column defaults).
  6. Owners forward to at most 10 targets; internal ones always while forwarding is on, external ones only with the switch.
  7. Owners have at most 20 aliases, on their own domain, delivering only to them, under the `AddressClaims` rules.
  8. Password change needs the current password (Dovecot), follows the mailbox rules, kicks IMAP and ends the other portal sessions.
  9. No email reset for a forgotten mailbox password: ask the domain's manager.
  10. Help names the domain's managers, else `EMAILSQUIRE_SUPPORT_CONTACT`, else a generic line; never server admins.
  11. Portal writes are audited with the mailbox as actor.
  12. New audit actions: `portal.password_changed`, `portal.forwarding_changed`, `portal.alias_created`, `portal.alias_deleted`, `domain.self_service_changed`.
  13. Portal language: the browser's saved choice, then the browser language, then English.
  14. Out of scope: vacation and autoreply (Sieve), two-factor for mailbox users, app passwords.

Still open (from the brief): nothing for phase 3.

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
