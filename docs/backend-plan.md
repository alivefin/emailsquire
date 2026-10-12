# EmailSquire: backend plan

Oct 9, 2026 · draft 2

This plan covers the backend for **EmailSquire**, a replacement for ISPmail Admin. It is built with **Laravel** and targets servers built with the ISPmail guide's **Trixie edition only** (Debian 13, Postfix, Dovecot 2.4, MariaDB, rspamd, Roundcube, Apache with certbot).

The core rule is that **Postfix, Dovecot and Roundcube must not notice the admin app exists.** The guide's three tables stay as they are, and the queries in `/etc/postfix/mysql-*.cf` and Dovecot's SQL config keep working unchanged. Everything the app needs beyond that goes into its own database schema.

## 1. What is already on the server

| Component | What the app relies on | How the app talks to it |
|---|---|---|
| MariaDB `mailserver` DB | `virtual_domains`, `virtual_users`, `virtual_aliases` (schema from the guide's installer, `ispmail.sh`). Postfix and Dovecot read them live, so a committed row takes effect immediately with no reload. | SQL, with a new least-privilege DB user |
| Postfix | Virtual domain, mailbox and alias maps (`/etc/postfix/mariadb-*.cf`), the `email2email` map, and a sender login map (see below) | Nothing directly; only through the tables. Local SMTP (port 25 on localhost) for sending invitation and reset emails. |
| Dovecot 2.4 | SQL passdb/userdb; `mail_home = /var/vmail/%{user \| domain}/%{user \| username}` with `~/Maildir`; quota plugin (`quota_storage_size = 5G`; the userdb query returns `IF(quota > 0, CONCAT(quota, 'B'), NULL)`, so `0` means "use the default"). The installer does **not** enable the doveadm HTTP API; installing the app adds it and a dedicated auth socket (section 8). | Doveadm HTTP API on localhost (quota, kick, user lookup), and the Dovecot auth socket `/run/dovecot/emailsquire-auth` (password checks) |
| rspamd | Signs outgoing mail as Postfix's milter (`inet:127.0.0.1:11332`). DKIM keys at `/var/lib/rspamd/dkim/$domain.$selector.key` (owned by `_rspamd`), selectors in `/etc/rspamd/dkim_selectors.map`, which installing phase 4 moves to `/etc/rspamd/emailsquire/dkim_selectors.map` (section 9) | Files written by the DKIM helper (phase 4) |
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
| Auth | **Fortify**, headless: console sign-in, TOTP 2FA and recovery codes, password reset, password confirmation (used as step-up). **`laravel/passkeys`** for WebAuthn, already installed through Fortify, behind app routes and an app service. It replaces `laragear/webauthn`, found abandoned on Oct 11, 2026; the first-party package uses `web-auth/webauthn-lib` for verification. A custom guard handles mailbox users (section 5.2). |
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
                 │ FastCGI, unix socket /run/php/emailsquire/fpm.sock
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
- **No root for helpers or services.** Maildir work runs as `vmail`, the owner of `/var/vmail`. DKIM key work runs as `_rspamd`. The web process cannot touch either directory.
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
GRANT SELECT, UPDATE (status, attempts, last_error, note, result, run_after, updated_at) ON emailsquire.helper_tasks TO 'vmail'@'localhost';
CREATE USER '_rspamd'@'localhost' IDENTIFIED VIA unix_socket;            -- phase 4
GRANT SELECT ON mailserver.virtual_domains      TO '_rspamd'@'localhost';
GRANT SELECT, UPDATE (status, attempts, last_error, note, result, run_after, updated_at) ON emailsquire.helper_tasks TO '_rspamd'@'localhost';
```

Each helper's task query filters on its own task types. Since phase 4 the helpers may update only the columns they write, never `type`, `payload`, `address`, `domain_name` or `applied_at`, and insert nothing. A view per helper (`helper_tasks_maildir`, `helper_tasks_dkim`) would also keep each helper off the other's rows; it isn't built.

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
| `passkeys` | WebAuthn credentials from `laravel/passkeys`, with the app's `disabled_at` for clone handling. Credential ids use a case-sensitive ASCII collation. |
| `sessions` | Laravel's database session table. "Sign out everywhere" deletes a user's rows. The step-up time is Laravel's own `auth.password_confirmed_at` session key. |
| `password_reset_tokens` | Laravel's, for forgotten-password links (the `users` broker, 30 minutes) |
| `admin_setup_tokens` | Setup links from `emailsquire:create-admin` (the `admin_setup` broker). Kept apart from reset links: a broker finds a token by email alone, and setup signs in without asking for the password. A user with confirmed TOTP or a usable passkey still has to pass the two-factor challenge after it. |
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
- **2FA (phase 2b, with passkeys added Oct 11, 2026):**
  - Fortify TOTP with confirmation and 8 single-use recovery codes. The secret and the codes are encrypted with `APP_KEY`. Window 1 (one 30 s step either side), and each code works once per secret: `TwoFactorCodeProvider` marks its period used with an atomic cache add.
  - Passkeys (`laravel/passkeys`) use `userVerification: required`, `residentKey: preferred` and attestation `none`. A verified passkey signs in without an email or password and counts as two factors. Password and setup-link sign-ins ask for TOTP, a passkey or a recovery code when a second factor exists; a passkey-only user is offered the passkey and recovery codes.
  - Confirmed TOTP or at least one usable passkey satisfies required two-factor. Disabled passkeys do not count. `GET /me` reports `second_factor` and the number of usable `passkeys`; `two_factor.confirmed` still describes TOTP only.
  - The first second factor creates 8 recovery codes, shown once. Adding another factor keeps existing codes. Removing the last factor deletes the codes, but is refused with 409 `last_second_factor` while two-factor is required.
  - The RP ID is the host of `APP_URL`, and the allowed origin is exactly its origin. Challenges live in the session, expire after 5 minutes and are spent once through an atomic cache nonce. Failed assertions answer uniform 422 `passkey_failed`; a signature counter that fails to increase (unless both counters are zero) disables the passkey, queues a notice and answers 422 `passkey_cloned`.
  - My profile lists passkeys and lets the user add, rename and remove them. Add and remove need step-up; rename does not. At most 10 per user, disabled ones included (422 `too_many_passkeys`). `passkey-login` allows 10 requests a minute per IP across passwordless and two-factor options and assertions. Registration and passkey step-up use the shared `step-up` limiter.
  - Both the Users API reset and `emailsquire:reset-two-factor` delete all passkeys and recovery codes.
  - **Required for server admins** (`EMAILSQUIRE_REQUIRE_TWO_FACTOR`: `admins` by default, or `everyone`; `off` is for the test suite only and `doctor` fails it). Until it is on, `RequireTwoFactor` answers 403 `two_factor_required` on every authenticated route except `/me`, `/me/two-factor*`, `/me/passkeys*`, `/me/recovery-codes`, `/me/password` and `/auth/*`, and the SPA sends the user to My profile's two-factor section.
  - Optional for domain managers, unless config requires it.
- **Onboarding and recovery:**
  - Invitations (brief 6.4) are 256-bit random tokens, stored hashed, single-use, expiring after 72 h.
  - Password reset (brief 6.5) uses Fortify's broker with a 30 min expiry. The response is the same whether or not the account exists, and requests are throttled to 5 an hour per email and 20 an hour per IP. The emailed job is encrypted on the queue (`ShouldBeEncrypted`). A reset ends every session of the user and signs nobody in; a password change ends the other sessions. Both send a security notice.
- **Bootstrap:** `php artisan emailsquire:create-admin you@example.org` prints a one-time setup link. There is no default credential, no seeder that creates users in production, and no setup page exposed to the web.

### 5.2 Mailbox users (`mailbox` guard, portal, phase 3, as built)

- **Principal:** `MailboxUserProvider` returns a read-only `MailboxPrincipal` (implements `Authenticatable`: address, domain id, quota, stored hash) built from the `virtual_users` row. `retrieveById()` re-reads the row on every request and returns `null` when it is gone or has a `disabled_mailboxes` row, so deleting or disabling a mailbox ends its portal sessions at the next request. The session also keeps the hash it signed in against (`AuthenticateSession`), so a password set elsewhere (an admin's reset, Roundcube) ends it too.
- **Sign-in routing (`SignInRouting`, before Fortify's pipeline):**
  1. A console account with that email whose password matches runs the console pipeline unchanged (two-factor, then `web`), answering `kind: 'console'`. With `mailbox: true` in the body (the sign-in card's "I want to manage my mailbox") this step is skipped, so someone whose console and mail passwords are the same still reaches their portal; that request can only end in the portal or the refusal, never in the console or its two-factor step, and still counts against `login`.
  2. Otherwise, when the address's domain is hosted here, it counts against `mailbox-login` (5 a minute per address and IP, 30 a minute per IP) and Dovecot is asked through `PasswordChecker`, mailbox or not, so the refusal takes `auth_failure_delay` either way. Only an existing, enabled mailbox whose password Dovecot accepts signs in on `mailbox`: `{kind: 'mailbox'}`. An address on a domain not hosted here is refused without asking Dovecot (it tells nothing that MX records don't).
  3. Every refusal is Fortify's one 422 ("Email or password is wrong."). The fail2ban line says `kind=mailbox` when the address is a mailbox (enabled or not) and `kind=console` otherwise. A mailbox sign-in clears its own `mailbox-login` count, never `login`.
- **Dovecot checks the password** (`DovecotAuthClient`, the auth socket `/run/dovecot/emailsquire-auth`, `service=emailsquire`, the client's IP as `rip=`; section 8). Dovecot understands every scheme on the server (`BLF-CRYPT`, `CRYPT`, restored `SHA*-CRYPT`, all verified on 2.4.1), which matters because Roundcube's password plugin writes `{CRYPT}`; the attempt lands in Dovecot's auth log and its per-IP penalty. Dovecot answers a failure only after `auth_failure_delay` (2 s), for an unknown user and a wrong password alike (mail truth measures both, `tests/MailTruth/PortalTest.php`), so the client's timeout is 10 s and the app adds no delay. A socket that can't be reached, or doesn't answer in time, fails closed with the same refusal; the error log line says which ("Dovecot auth timed out" or "the Dovecot auth socket could not be reached"), with the reason and never the address.
- **The e2e stand-in:** with `APP_ENV=e2e`, and only then, `PasswordChecker` is `FakeDovecotAuthClient`, which checks `{BLF-CRYPT}` hashes with `password_verify()` and refuses to run in any other environment. `doctor`'s `runtime.test_environment` fails an `e2e` or `testing` environment on the production mail root `/var/vmail`.
- **Password change (`PUT /portal/password`):** the current password is checked by Dovecot; the new one follows the mailbox rules (12 characters to 72 bytes, strength 3 or more, HIBP when on) and is stored as `{BLF-CRYPT}` + `password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12])`, but only if the stored hash is still the one this session signed in with. The mailbox's IMAP sessions are kicked and its other portal sessions end; this one moves to a new session id and stays signed in. 6 attempts a minute per mailbox (`mailbox-password`), wrong current passwords included.
- **Self-service switches** (per domain, `domain_settings`): `allow_user_forwarding` (default on), `allow_user_aliases` (off) and `allow_external_forwarding` (off). Server admins and the domain's managers set them (`PATCH /domains/{d}/self-service`, audited `domain.self_service_changed`); viewers read them. While a switch is off the portal's writes for it answer 403 `self_service_disabled` (checked before the body and again under the domain row's lock) and the list stays readable. Per-mailbox overrides (`mailbox_settings`) are not used yet.
- **Owner limits:** at most 10 forwarding targets; targets on any domain of this server are allowed while forwarding is on, others need external forwarding (422 `external_forwarding_disabled`). At most 20 aliases, each a local part on the owner's own domain that delivers only to their mailbox. `AddressClaims` judges every write with the owner as principal, plus: no local part from `emailsquire.owner_reserved_locals` (postmaster, abuse, hostmaster, webmaster, admin, root, …), and no alias while the domain has a catch-all that doesn't go only to this mailbox (the alias would take mail the catch-all now gets). Every refusal of an address is one answer, 422 `address_unavailable`, whatever the reason. Nothing may loop: a forwarding target can't be one of the owner's own aliases, and without a kept copy it can't be any address whose mail comes back to the owner (an alias on any domain with the owner among its destinations, an address with no rows whose domain's catch-all delivers to them, or a chain of these): 422 `validation_failed` on that `targets.N`. Postfix marks an address "been here" only when it expands to itself, so a forward-only loop runs to `virtual_alias_recursion_limit` and the mail is refused with a 4xx ("451 4.6.0 Alias expansion error", mail truth), even mail sent to a shared alias the owner doesn't own; with a kept copy it stops at the mailbox. An address the owner forwards to can't become their alias (`address_unavailable`). Portal writes count against `portal-writes`, 30 a minute per mailbox.
- **Audit:** portal writes are audited with `actor_type = mailbox` and the address as actor: `portal.password_changed`, `portal.forwarding_changed`, `portal.alias_created`, `portal.alias_deleted` (group `mailboxes`). The activity log shows the address.
- **Language:** the portal has no `users` row, so it uses the language saved in the browser, then the browser's, then English; the portal header has a switch.
- **Help:** the domain's active managers (name and email); with none, `emailsquire.support_contact` (`EMAILSQUIRE_SUPPORT_CONTACT`, free text); with neither, a generic sentence. Server admins are never listed. There is no email reset for mailbox passwords (brief 6.5): sign-in and Help say to ask the domain's manager.

### 5.3 Sessions and request protection

- **Cookie:** `SESSION_COOKIE=__Host-emailsquire`, `SESSION_SECURE_COOKIE=true`, `same_site=strict`, `http_only=true`, no domain, path `/`. Session data stays server-side (`database` driver), so sessions can be revoked ("sign out everywhere" in brief 6.8).
- **Lifetime:** `SESSION_LIFETIME=30` minutes idle for both guards. A small middleware adds an absolute limit: 12 h for console users, 2 h for the portal. The session ID rotates on sign-in, sign-out and any role change (`session()->regenerate()`).
- **Step-up re-authentication** with the `step-up` middleware (`RequireStepUp`): `POST /auth/confirm-password` with the password, a confirmed TOTP code or a passkey opens a 300 s window; outside it the route answers 423 `step_up_required`, and the SPA asks in a dialog and retries the request once. Required for: deleting a domain, turning 2FA on or off, viewing or regenerating recovery codes, adding or removing a passkey, and (phase 2c) changing roles or grants and deleting a console user.
- **CSRF:** Laravel's `ValidateCsrfToken`, with the SPA sending `X-XSRF-TOKEN` from the `XSRF-TOKEN` cookie. On top of that, `SameSite=Strict` and an `EnsureSameOrigin` middleware that rejects state-changing requests whose `Origin` (or `Sec-Fetch-Site`) is not same-origin.
- **Rate limits:** `login` (5 a minute per address and IP, counting every attempt; a finished sign-in, with or without the two-factor step, clears it), `two-factor` (5 a minute per pending sign-in and IP), `step-up` (6 requests a minute per user, shared by password/code/passkey confirmation, passkey options and registration, and password changes), `passkey-login` (10 requests a minute per IP, shared by passwordless and two-factor passkey options and assertions), `logout-all` (5 an hour per user), `forgot-password` and `reset-password`, `mailbox-login` (5 a minute per address and IP and 30 per IP, for sign-ins the console account did not take), `mailbox-password` (6 a minute per mailbox), `portal-writes` (30 a minute per mailbox), and `throttle:api` on writes. All use the database cache store. Every failed sign-in is logged as one line in a fixed format, and a fail2ban filter for it ships with the app.

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
5. Catch-all sources (`@domain`) are admin or manager only. They need the guide's `email2email` map so that existing mailboxes still receive their mail; `doctor` checks for it before the feature can be enabled. A catch-all target whose mail would come back into the catch-all (an address of the domain that is neither a mailbox nor forwarding, or a loop) is refused with 422 on `targets.N`. Console forwarding of a named source is walked the same way (`AliasExpansion::forwardingProblems()`): a target whose mail goes round a loop is refused (`forwarding.target_loops`), and while the domain has a catch-all so is a target on the domain that is neither a mailbox nor forwarding, a typo the catch-all would swallow (`forwarding.target_unknown`). Postfix marks an address "been here" when it expands to itself, so a loop through an address that keeps a copy delivers and is allowed. A walk that would look at more than 1000 addresses (Postfix's `virtual_alias_expansion_limit`) is refused too (`forwarding.target_too_large`).

## 7. API surface (v1)

All JSON under `/api/v1`. Every list is paginated (`?cursor=`, `?q=`, `?limit=`, max 200 per the brief's page size). Responses go through API Resources, so the `password` column can't be serialised by accident; it is also `$hidden` on the model.

```
POST   /auth/login                      {email, password} → me | {two_factor: true}
POST   /auth/setup                      {token, email, password, password_confirmation} → me | {two_factor: true}
POST   /auth/passkey/options            passwordless assertion options (no email, empty allowCredentials)
POST   /auth/passkey                    {credential} → {kind: 'console', …me}
POST   /auth/two-factor-challenge/passkey-options   assertion options for the pending user
POST   /auth/two-factor-challenge       {code} | {recovery_code} | {passkey} → me
POST   /auth/logout   /auth/logout-all  (logout-all ends the user's other sessions)
POST   /auth/confirm-password/passkey-options   assertion options for the signed-in user
POST   /auth/confirm-password           {password} | {code} | {passkey} → 204, opens the 300 s step-up window
POST   /auth/forgot-password {email}    POST /auth/reset-password {token, email, password, password_confirmation}
POST   /auth/invitations/lookup         {token} → the invitation and its state (phase 2c)
POST   /auth/invitations/accept         {token} signed in as the invited email | {token, name, password, password_confirmation} → me
GET    /me                              PATCH /me {name, locale}   PUT /me/password {current_password, password, password_confirmation}
POST   /me/two-factor                   start enrolment (step-up)   DELETE /me/two-factor (step-up)
GET    /me/two-factor/setup             secret and QR SVG of the unconfirmed setup (step-up)
POST   /me/two-factor/confirm           {code} → 8 recovery codes (step-up)
GET|POST /me/recovery-codes             show or regenerate (step-up)
GET    /me/passkeys                    {data: [{id, name, created_at, last_used_at, disabled}]}
POST   /me/passkeys/options            creation options (step-up)
POST   /me/passkeys                    {name, credential} → 201 {passkey, recovery_codes?} (step-up, no-store)
PATCH  /me/passkeys/{id}                {name} → passkey (no step-up)
DELETE /me/passkeys/{id}                → 204 (step-up; 409 last_second_factor while required)

GET    /dashboard                       counts, near-quota mailboxes, recent activity
GET    /search?q=                       domains, mailboxes and forwarding across allowed domains

GET    /domains                         POST /domains              (admin)
GET    /domains/{domain}                DELETE /domains/{domain}   (admin, step-up, typed confirmation)
GET|PATCH /domains/{domain}/settings  {max_mailboxes, max_quota_bytes_total} (any role reads, admin changes)
GET    /domains/{domain}/dns            {result: {checked_at, checks} | null, checking} (any role; the first read queues a check)
POST   /domains/{domain}/dns/recheck    (admin or manager; 1 a minute per domain, 429 after; audited as domain.dns_checked)
GET    /domains/{domain}/dkim           {active, keys: [{selector, state, txt, created_at}], pending_task} (any role)
POST   /domains/{domain}/dkim/generate | inspect   POST /domains/{domain}/dkim/{selector}/activate {force?} | retire   (admin, step-up; 202)

GET    /domains/{domain}/mailboxes      POST …
GET    /domains/{domain}/mailboxes/{local}   PATCH (quota, display name; receiving and sendonly with the section 10 extension) DELETE
POST   /domains/{domain}/mailboxes/{local}/password       {generate:true} → password shown once
POST   /domains/{domain}/mailboxes/{local}/disable | enable
GET    /domains/{domain}/mailboxes/{local}/usage          doveadm quota
POST   /domains/{domain}/mailboxes/{local}/welcome        {to, locale} → 202, setup instructions, never the password (phase 2d)

GET    /domains/{domain}/forwarding     grouped by source: [{source, targets:[{address, kind: local|external}]}]
PUT    /domains/{domain}/forwarding/{local}   {targets:[…], keep_copy:bool}   full replace, one transaction
DELETE /domains/{domain}/forwarding/{local}
PUT    /domains/{domain}/forwarding/@  {targets:[…]}   catch-all (admin or manager; 409 catch_all_unsupported unless doctor's postfix.alias_maps is OK)
DELETE /domains/{domain}/forwarding/@  (admin or manager, on any server)

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

- **Passkeys add** the WebAuthn routes above. Only console users can use them. Fortify's own passkey routes stay off. Options and credential responses use `Cache-Control: no-store`; registration recovery codes stay in component state, outside the SPA caches. A passwordless sign-in regenerates the session, signs the mailbox guard out and clears pending two-factor and step-up state, as the password path does. Add/remove queue security notices; audit actions are `user.passkey_added` (`changes: {name}`), `user.passkey_removed` and `user.passkey_renamed` (`changes: {name: [old, new]}`), in the account group.
- **Phase 3 adds** the self-service and portal routes above. `POST /auth/login` answers `{kind: 'mailbox'}` for a portal sign-in and adds `kind: 'console'` to the console answer. `POST /auth/logout` signs either kind out. Portal writes need the domain's switch: 403 `self_service_disabled` before the body is read. Problem codes: 422 `address_unavailable` (one answer for every taken, blocked, reserved or shadowed address), `external_forwarding_disabled`, `too_many_targets`, `too_many_aliases`; 503 `password_check_unavailable` when Dovecot's auth socket can't be reached during a password change. Throttles: `mailbox-login`, `mailbox-password`, `portal-writes` (section 5.3).
- **Phase 2d adds** the audit-log and welcome routes above.
  - `GET /audit-log`: rows the caller may read (section 13), ordered `occurred_at desc, id desc`. `domain` is a domain name in ASCII or Unicode; one that is unknown or outside the caller's domains gives an empty page, not a 404. `actor` is a user id, `cli` or `system`. `action_group` is one of `mailboxes`, `forwarding`, `domains`, `blocked`, `access`, `account` (`AuditActions::GROUPS`). `from` (inclusive) and `to` (exclusive) are ISO 8601 date-times with an offset. `before_id` pins the later pages to the first page's newest row, so rows written meanwhile don't shift them.
  - `POST …/welcome`: managers and admins (`can:update,mailbox`; a viewer gets 403). `to` is one address (`email:rfc`, at most 254 characters, no display name, list or header characters: 422) and `locale` is `en` or `fi`. The mail is queued and audited `mailbox.welcome_sent` with `changes: {to}`. Only mails that were queued count against the limit of 10 an hour per user; the 11th gets 429.
- **Phase 2c adds** the access, invitation, Users and settings routes above. The invitation link is `{APP_URL}/invite#{token}`: the token rides in the fragment, which browsers never send, and the API takes it only in POST bodies. An inviter who is deactivated or loses the server-admin role, or a manager who loses the manager role on a domain, has their open invitations (to that domain) revoked for good, each audited `invitation.revoked`; an invitation that makes a signed-in user a server admin ends their other sessions, as a role change on the Users screen does. Throttles: `invitations` 30 an hour per user (create and resend), `invitation-lookup` 30 a minute and `invitation-accept` 10 a minute per IP. Problem codes: 409 `already_has_access`, `user_deactivated`, `invitation_closed`, `cannot_change_self`, `last_server_admin`; 410 `invitation_invalid` (with its `state`) for a closed invitation link; 422 `domain_limit`.
- **Phase 2b adds** the two-factor, step-up, password and `/me` routes above. Problem codes: 423 `step_up_required`, 403 `two_factor_required`.
- **Phase 1 adds** `GET /domains/names`, `GET /domains/{domain}/addresses/check?local=`, `POST /domains/{domain}/mailboxes/bulk`, `/me/pinned-domains` and `POST /auth/setup`, and uses page numbers instead of cursors.
- **Forwarding is a full-replace `PUT` per source.** The brief merges aliases and redirects into one "forwarding" concept with several targets. Replacing the whole row set for a source in one transaction, under the source lock, avoids partial states. `keep_copy` maps to the `me → me` row.
- **A generated password is returned exactly once,** in that response, with `Cache-Control: no-store`. It is never logged. `password`, `password_confirmation`, `current_password` and `targets` are not put into the session on validation failure (`$dontFlash`).
- **Every mutating endpoint writes the audit log in the same transaction** as the change, through an `AuditLog::record()` service that the repositories call. Model observers are not used, because they would miss query-builder writes and run outside the transaction boundary.

## 8. Integrations and tooling

### Dovecot (doveadm HTTP API)

The shared key is restricted to `"quota get" kick user`; the space-separated allowlist refuses mail-reading commands such as `fetch`. Doctor checks that `doctor-probe@invalid` is absent (user lookup exit code 67) before requesting a metadata-only `fetch` (`field: [guid]`, a random 32-hex mailbox search). An existing probe user fails without fetching. Only an explicit command-level `unAuthorized` denial passes; invalid requests, malformed replies, HTTP key rejection and transport failures retain their own diagnostics. Existing installations must add this allowlist when upgrading.

Installing the app adds `/etc/dovecot/conf.d/99-emailsquire.conf`. This was verified on Dovecot 2.4.1 (Debian Trixie) in the phase 0 prototype:

```
doveadm_api_key = <random, also in .env as DOVEADM_API_KEY>
doveadm_allowed_commands = "quota get" kick user
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

### rspamd DKIM (phase 4, as built)

- **The helper.** `helpers/dkim.php` and `helpers/dkim/*`, run every minute as `_rspamd` by `emailsquire-dkim.{service,timer}`. Like the maildir helper it is framework-free, connects over the MariaDB unix socket as its OS user, never reads `.env` and trusts nothing in a task row. It runs no process: keys come from PHP's OpenSSL (`openssl_pkey_new`, RSA 2048, PKCS#8 PEM, the format rspamd reads), not `rspamadm`. Only the key directory's owner may run it (root is refused), one run at a time (`<dkim-dir>/.emailsquire-dkim.lock`).
- **Tasks** (`helper_tasks.type`, payload `{domain, domain_id, selector?}`, answer in `helper_tasks.result`):
  - `dkim.generate`: a new key, not used yet → `{selector, txt}`. Selectors are `esYYYYMMDD`, then `…b` to `…z` the same day.
  - `dkim.activate`: points the selector map at an existing key → `{selector, txt}`.
  - `dkim.retire`: deletes a key file, never the active one → `{selector}`.
  - `dkim.inspect`: the map's selector and every key's record, including keys the installer made → `{active_selector, keys}`. When the map has no line for the domain but `<domain>.dkim.key` exists, that key is the active one: rspamd's `dkim_signing` falls back to its default selector `dkim` (`try_fallback`, on by default and in the guide's config), so it signs with that file.
- **Checks before any path is built:** the domain matches a strict lowercase host name pattern and its exact spelling in `virtual_domains`; a selector matches `es\d{8}[a-z]?`. Paths come only from `--dkim-dir` (default `/var/lib/rspamd/dkim`) and `--selector-map` (default `/etc/rspamd/emailsquire/dkim_selectors.map`), every component checked with `lstat` (no symlinks). Keys are written `0600` through a hard link that never replaces a file. Results hold public data only. Refusals fail a task at once; I/O errors retry with backoff and fail on the fifth attempt. A missing map directory is such an error for every type, so retire and inspect never assume "no active key".
- **The selector map** is rspamd's key/value map. The helper rewrites it atomically (a temp file in the same directory, fsync, `rename()`, directory fsync) and changes only its domain's line. It lives in a directory of its own, `/etc/rspamd/emailsquire` (`root:_rspamd 0770`), never in `/etc/rspamd`: write access there would let `_rspamd` add configuration that rspamd's root process loads. rspamd notices the renamed file by itself and needs no reload (mail truth sees the new selector on the next message).
- **In the app** (`App\MailServer\Dkim`, server admins only, each write behind step-up): Generate, then publish the record, then Activate once the latest DNS check shows *this* key (the stored check's key hash must match it, and the check must be newer than the key), or with `force` (audited as forced). The old key stays until Retire, which is refused for the active key and for 15 minutes after the domain's latest activation (409 `dkim_recently_activated` with `Retry-After`), because rspamd may still sign with the previous key until it has re-read the map. One DKIM task per domain at a time (409 `dkim_busy`). The app reads finished tasks back when the DKIM card or a DNS check asks (`Dkim::sync()`, marked with `helper_tasks.applied_at`), parses each `txt` as an RSA public key, and fails a malformed result without changing anything. Inspect imports installer keys; their non-`es…` selectors are shown but can't be activated or retired from the console.
- **Deleting a domain** removes its `domain_dkim` rows and fails its open DKIM tasks, but its key files and map line stay: remove `/var/lib/rspamd/dkim/<domain>.*.key` and the domain's line in the selector map by hand.

### DNS health check (phase 4, as built)

`App\MailServer\Dns\DnsHealth`, run by the queued `CheckDomainDns` job per domain: every 6 h from the scheduler (`emailsquire:dns-checks`, not audited), on the first read of a domain that has no result, and on "Check now" (`POST …/dns/recheck`, admins and managers, 1 a minute per domain, audited as `domain.dns_checked`). The latest result is stored per domain (`domain_dns_checks`); readers never query DNS. Lookups go through a `DnsResolver` seam: `SystemDnsResolver` (`dns_get_record()`, absolute names) everywhere, and `FakeDnsResolver` (the fixture `tests/e2e/fixtures/dns.json`) only in `APP_ENV=e2e`. A run stops asking after 30 s (`emailsquire.dns.budget`); the job's timeout is 100 s and the database queue's `retry_after` 150 s.

Each check answers `{status: ok|warn|fail|info, found, expected?, hint_key, publish?}`, where `publish` is `{name, type, value, zone}`, the record to copy:

- **mx:** an MX names `mail_host` (an MX naming an alias of it fails; `mail_host` being a CNAME warns, and so does another host's MX with a lower preference number than every MX naming `mail_host`, `mx.lower_priority`: mail goes there first).
- **spf:** one `v=spf1` record that covers this server through `mx`, `a:<mail_host>`, an `a`/`a:<name>` whose A/AAAA records, or an `mx`/`mx:<name>` whose MX hosts' A/AAAA records, hold a server address (with the term's `/prefix` and `//prefix6`), or an `ip4:`/`ip6:` network holding a server address. Only `include:`/`redirect=`, or a soft ending without the server, warns; `+all` or a `/0` network warns; none, several or a hard fail without the server fails. The suggestion is `v=spf1 mx -all`, or `mx` added to the existing record.
- **dkim:** the active key's record at `<selector>._domainkey` (tags compared: `p`, `k`, `v`). A pending key's record is checked too, which is what Activate reads.
- **dmarc:** one `_dmarc` record with a valid `p=`; the suggestion is `v=DMARC1; p=none; rua=mailto:postmaster@<domain>`.
- **ptr:** each server address (`EMAILSQUIRE_SERVER_IPS`, else `mail_host`'s A and AAAA) has a PTR naming `mail_host`, which resolves back to it.
- **mta_sts** and **autoconfig:** information only, never red.

DNS answers are scrubbed to UTF-8 and capped (20 values of 1 KiB each) before they are stored.

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
- The doveadm API and the auth socket `/run/dovecot/emailsquire-auth` are reachable; the mail root exists and is owned by `vmail`; the trash directory is on the same filesystem; the DKIM key directory is owned by `_rspamd` (`fs.dkim_dir`); the selector map's own directory is writable by `_rspamd`, `/etc/rspamd`, `local.d`, `override.d` and the files in them are not, and rspamd's `selector_map` names the map (`fs.dkim_selector_map`).
- Whether the `email2email` map is in `virtual_alias_maps`, which catch-all support needs (`postfix.alias_maps`). A map file the app can read counts when its query is the guide's `SELECT email FROM virtual_users WHERE email='%s'`, alone or with section 10's `AND enabled = 1` / `AND sendonly = 0`, whatever the file's name; one it can't read (the installer's are root and postfix only) counts when its name contains `email2email`. The console reads the same answer as the app's user, cached for 10 min, and running the check clears that cache.
- Which `smtpd_sender_login_maps` is active (read from `/etc/postfix/main.cf`), and whether its `.cf` file still holds the installer's `MAILSERVER-PASSWORD-HERE` placeholder. The installer's `going_live` step doesn't replace the placeholder in `mariadb-sender-login-maps.cf`.
- PHP and runtime checks:
  - `disable_functions` is set in the app's FPM `php.ini`.
  - `APP_DEBUG=false` and `APP_ENV=production`.
  - The `config:cache` and `route:cache` files are present.

### Moving from ISPmail Admin

The tables are the same, so no data migration is needed. `sudo php artisan emailsquire:import-ima --database=migrator [--drop]` copies `blacklist_email` into `blocked_addresses` (HTML entities in the old rows are decoded) and, with `--drop`, drops the old table. Run `doctor` first: rows written by earlier tools may sit under the wrong `domain_id`, and `doctor` lists them.

## 9. Hardening and operations

Use the [installation and operator guide](install.md) for the current deployment layout, build and cache steps, database grants, Apache and PHP-FPM configuration, systemd services, helpers, Dovecot/rspamd setup, fail2ban, account recovery, upgrades and backups. It replaces the duplicated operator procedures here and identifies deployment details that still need verification on the target host.

**Code rules:**

- No raw SQL string building. Query builder bindings only, and `DB::raw` is allowed only in the repository layer, with a lint rule for it.
- No `exec` in the app; FPM `disable_functions` enforces it.
- The API never returns HTML. Output escaping is the frontend's job: React escapes by default, and a lint rule bans `dangerouslySetInnerHTML`.
- `APP_DEBUG=false` in production is checked by `doctor` and by a boot-time guard that refuses to serve if it is on.

## 10. Optional schema extension (phase 4, as built)

Only if you want the "stop receiving mail" and "send-only" switches. This is a documented change the server admin applies on purpose. The app never does it itself, and Laravel migrations never touch `mailserver`. Without it everything else works: `PATCH /domains/{d}/mailboxes/{m}` with `receiving` or `sendonly` answers 422 `schema_extension_missing` (on that field), and the mailbox resource reports both as `null`.

**Detection.** `App\MailServer\SchemaExtension` looks for the columns in `information_schema.COLUMNS` (guide schema, table `virtual_users`), one answer per column, remembered for 10 minutes. After you add the columns, wait up to 10 minutes for the console switches to appear; no service restart is needed. `SchemaExtension::flush()` clears only these entries internally, but no dedicated Artisan command exposes it. Never run `cache:clear` on a running server: it also removes used-TOTP markers, single-use passkey challenge markers and rate limits. `GET /api/v1/me` carries the result as `capabilities.schema_extension.{receiving, sendonly}`, next to `capabilities.catch_all`. The app's database user already has `SELECT, INSERT, UPDATE, DELETE` on `virtual_users` (section 4.1), so it sees and can write the new columns.

**1. The columns** (as the MariaDB admin, once):

```sql
ALTER TABLE mailserver.virtual_users
  ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN sendonly TINYINT(1) NOT NULL DEFAULT 0;
```

Existing mailboxes keep receiving (`enabled = 1`) and stay normal mailboxes (`sendonly = 0`). Add the columns first and the server changes below second; the app never writes either column on its own.

**2. Postfix: refuse direct mail at RCPT.** On the installer's layout `mariadb-email2email.cf` serves two purposes: it is in `virtual_alias_maps` (an address that is a mailbox is its own alias, so a catch-all never takes its mail) and it is `smtpd_sender_login_maps` (who may send as which address). Only the first use must be gated. **Leave `mariadb-email2email.cf` itself untouched**: a gated sender-login map would stop a send-only or non-receiving mailbox from sending as itself wherever submission uses `reject_sender_login_mismatch`. Gate a *copy*, and use the copy only in `virtual_alias_maps`:

```
# mariadb-virtual-mailbox-maps.cf: add the condition
query = SELECT 1 FROM virtual_users WHERE email='%s' AND enabled = 1 AND sendonly = 0

# new file mariadb-email2email-receiving.cf: copy mariadb-email2email.cf (same owner root:postfix,
# mode 0640, same user/password/hosts/dbname lines), then change the query
query = SELECT email FROM virtual_users WHERE email='%s' AND enabled = 1 AND sendonly = 0
```

```
postconf -e 'virtual_alias_maps = mysql:/etc/postfix/mariadb-virtual-alias-maps.cf, mysql:/etc/postfix/mariadb-email2email-receiving.cf'
# smtpd_sender_login_maps stays mysql:/etc/postfix/mariadb-email2email.cf
systemctl reload postfix
```

(If your `virtual_alias_maps` lists more maps than the installer's two, replace only the `mariadb-email2email.cf` entry.) Keep `email2email` in the new file's name: doctor's `postfix.alias_maps` check and the console's catch-all gate recognise a map they can't read by that name (one they can read, by its query). Mail addressed straight to a mailbox with `enabled = 0` or `sendonly = 1` (and no alias row of its own) is then refused while the sender is still connected: `550 5.1.1 <address>: Recipient address rejected: User unknown in virtual mailbox table`. The sender gets the refusal from their own server; nothing is accepted and then lost.

**3. Dovecot: the delivery gate for everything else.** Postfix accepts any address that has a `virtual_aliases` row, and also anything an alias expands to, so aliases, portal aliases and keep-a-copy rows (`anna@x -> anna@x`) still reach the mailbox. The gate that covers them is Dovecot's `userdb` query, limited to LMTP so that nobody is locked out of their old mail. In the `userdb sql` block of the guide's SQL config (`/etc/dovecot/conf.d/…sql.conf`, whichever file holds `userdb sql {`) append to the `userdb` query only (leave `passdb` and `iterate_query` alone):

```
userdb sql {
  query = SELECT email as user, \
    IF(quota > 0, CONCAT(quota, 'B'), NULL) AS quota_storage_size \
    FROM virtual_users WHERE email='%{user}' \
    AND (enabled = 1 AND sendonly = 0 OR '%{protocol}' <> 'lmtp')
  iterate_query = SELECT email as user FROM virtual_users
}
```

then `doveadm reload`. `%{protocol}` is the Dovecot 2.4 variable for the service doing the lookup (`lmtp`, `imap`, `pop3`, `submission`, …); checked on Dovecot 2.4.1 in the project's trixie test host. For a mailbox with `enabled = 0` or `sendonly = 1` the LMTP lookup finds no user, and Dovecot answers Postfix `550 5.1.1 <address> User doesn't exist`. Keep your own `quota_storage_size` expression and the rest of the query as the installer wrote it; only the `AND (…)` line is new.

**Observed on the trixie test host** (`tests/MailTruth/SchemaExtensionTest.php`, with exactly the changes above), for `enabled = 0` and for `sendonly = 1` alike:
- sending: the mailbox signs in to submission (port 587 with SASL, `reject_sender_login_mismatch`, `smtpd_sender_login_maps` unchanged) and sends as itself to another local mailbox, which receives the message;
- direct mail: `RCPT TO` is answered `550 5.1.1 … User unknown in virtual mailbox table`;
- mail through an alias to the mailbox, and to an address with a keep-a-copy row: accepted at RCPT, then Postfix logs `status=bounced (host … said: 550 5.1.1 <address> User doesn't exist …)` and nothing reaches the Maildir (the sender gets a bounce message);
- IMAP `LOGIN` still succeeds, so the owner reads their old mail, and the password is unchanged;
- setting the column back to `1` / `0` delivers again, directly and through the alias, with no reload.

**What this means for people.** An alias or forwarding rule that points at a non-receiving or send-only mailbox bounces; it does not vanish silently. The owner can still sign in over IMAP and keep sending from the mailbox as itself (shown on the test host; this is why the sender-login map stays ungated). Send-only needs nothing more: the gates above stop its incoming mail. A catch-all (`@example.org`) that has the mailbox as target still takes the direct mail for addresses that are not accepted otherwise, which is the catch-all doing its job. If you add a `check_recipient_access` map for send-only as well, note that a submission service which sets its own `smtpd_recipient_restrictions` in `master.cf` ignores the one in `main.cf`; the Dovecot gate does not depend on that.

**Forwarding when receiving is off.** A forwarding rule *from* the address (`anna@x -> someone@elsewhere`, without keep-a-copy) keeps working: Postfix expands the alias, and only the targets are delivered to. The app does not touch `virtual_aliases` when it flips a switch. A keep-a-copy rule also forwards to its other targets, and its copy to the mailbox itself bounces at LMTP (the other targets are delivered).

**Before the changes are applied** the switches do nothing to mail, even where the columns exist: the app only writes the columns. The console words them that way too (they take effect after the server admin applies this section).

**Dropping the columns again.** Wait up to 10 minutes for detection cache expiry; no service restart is needed. The app also recovers on its own the first time a read finds a remembered column missing. Do not run `cache:clear` on a running server. Undo the Postfix and Dovecot changes first (put `mariadb-email2email.cf` back in `virtual_alias_maps`), otherwise their queries fail.

**Audit.** `mailbox.receiving_changed` and `mailbox.sendonly_changed`, object type `mailbox`, key the stored address, `changes: {old, new}` (booleans), one row per switch that really changes.

### Privileged maintenance and security invariants

Root maintenance uses the root-owned executable `scripts/root-artisan`, never plain `php artisan` in a release. Laravel requires executable configuration, service, package, route and event caches from paths normally writable by the service account. The wrapper exports all five `APP_*_CACHE` overrides and `VIEW_COMPILED_PATH` into a fresh root-owned mode-0700 temporary directory, executes from its own release directory, and retains a parent process to remove the directory on exit.

Console credential failures (unknown, deactivated and setup-pending accounts) and forgotten-password no-mail paths verify a precomputed dummy payload once, without generating a hash. Its encoded algorithm/work factors match configured bcrypt/Argon options. The shared console password broker and database token repository also pad unknown-user and missing/expired-token setup/reset attempts, while wrong live tokens retain their single real verification. Invitation acceptance rechecks the presented SHA-256 against the current row under its lock on every transaction attempt; resend rotation produces the unknown-token 404 `invitation_not_found` and grants nothing.

Portal forwarding follows SourceLock, domain, mailboxes, aliases. It locks mailboxes before the unindexed alias locking read and validates expansion with current alias locking reads, independent of `innodb_snapshot_isolation`. Deadlocks retry at most three attempts. AliasExpansion always reads mailbox existence without locks, including later steps of the walk, while retaining Postfix's alias precedence; it never takes a mailbox lock after an alias lock.

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
| **4. Mail hygiene** (done, Oct 11, 2026) | DKIM helper and records, DNS health check, catch-all (gated on `doctor`), optional schema extension support | Per-domain DNS page is green on a correctly set-up domain. Pest (API, roles, step-up, each DNS rule against a stub resolver, the helper against temp trees), mail truth (real rspamd signs with a key the console created and activated, verified against the published record; rotation and retire; catch-all delivery beside existing mailboxes; the section 10 switches) and Playwright (the DNS tab and DKIM card with the e2e resolver fixture, catch-all editing, desktop and phone, layout at tablet, one Finnish run) cover it |

Out of scope for now: autoreply. Roundcube already manages Sieve vacation through ManageSieve. Doing it in the portal would mean speaking ManageSieve with a Dovecot master user, a sizeable new trust boundary. Revisit after phase 3.

## 13. Decisions

Decided:

- **Laravel** (Oct 9, 2026).
- **Trixie only** (Oct 9, 2026). Restored Bookworm data on a Trixie server is still handled (section 1).
- **Same host:** the app runs on the mail server, which the helpers and unix-socket DB auth need.
- **No unlimited quota** (Oct 9, 2026). Every mailbox has a finite quota: `0` means the 5 GB default, and any other value is an explicit size. No Dovecot config change is needed.
- **Two-factor: TOTP and passkeys** (Oct 10–11, 2026). Phase 2b shipped Fortify TOTP with 8 recovery codes; passkeys now use `laravel/passkeys` behind app routes. `laragear/webauthn` was found abandoned, so its original selection is replaced. A passkey with required user verification signs in alone and satisfies required 2FA, as confirmed TOTP does. 2FA is required for server admins (`emailsquire.require_two_factor`: `admins` by default, or `everyone`) and optional for others. Dangerous actions accept a password, TOTP code or passkey confirmation within 300 s. Passkey add/remove need step-up; rename does not.
- **Email delivery** (Oct 10, 2026): Laravel mail over SMTP to the local Postfix (`127.0.0.1:25`), every email queued on the `jobs` table and sent by `emailsquire-queue.service`.
- **Languages: English and Finnish** (Oct 10, 2026). English is the default and the fallback. The console uses the user's saved language, then the browser's; server messages follow the same choice. Wording: `docs/i18n-glossary.md`.

- **Managers share only with the flag** (Oct 10, 2026). A manager may invite to, change roles on and remove people from their domain only while `emailsquire.managers_can_share` is on; the default is off.
- **Viewer role** (Oct 10, 2026). Read-only per domain: mailboxes, forwarding and who has access.
- **Managers can't add domains** (Oct 10, 2026). Creating and deleting domains stays with server admins.
- **Per-domain limits apply to everyone** (Oct 10, 2026), server admins included: at most `max_mailboxes` mailboxes and `max_quota_bytes_total` of quota. Only a server admin changes them; existing mailboxes stay when a limit is lowered.

- **Welcome email: settings only, never the password** (Oct 10, 2026; phase-2 spec decision 2). It sends the address (which is the username), IMAP and SMTP with their ports and security, and the webmail link to an address the sender types, in the language they pick. The password reaches the user some other way.
- **Who reads the audit log** (Oct 11, 2026; phase 2d). Server admins read every row. Anyone else reads a row only when its `domain_name` names a domain they have a role on and its action is not admin-only. Admin-only are `admin.*`, `maildir.*` and every `user.*` except `user.invited`; a server-admin invitation has no domain, so only admins see it. Rows match domains by name, so a domain created again under an old name shows the old history. One rule (`AuditVisibility`) serves `/audit-log`, `/audit-log/actors` and the dashboard. Nothing deletes audit rows; the app's DB user has only `INSERT, SELECT` on `audit_log`.

- **Mailbox portal** (Oct 11, 2026; phase 3):
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

- **Mail hygiene** (Oct 11, 2026; phase 4):
  1. DKIM keys come from the framework-free helper as `_rspamd`, with PHP's OpenSSL (RSA 2048); no `rspamadm`, no process.
  2. Selectors are `esYYYYMMDD`, with a letter suffix for a second key the same day.
  3. Rotation is Generate, publish, Activate (once DNS shows this key, or forced), Retire; the old key stays until Retire, at least 15 minutes after an activation.
  4. Only server admins manage DKIM keys; everyone with a role sees them and their records.
  5. Inspect reads the installer's keys through the helper, since the app can't read `_rspamd`'s files.
  6. The helper rewrites the selector map atomically (temp file and rename) in its own directory, `/etc/rspamd/emailsquire` (`root:_rspamd 0770`), which `selector_map` points at. This replaces the spec's group-writable map in `/etc/rspamd`, which would have let `_rspamd` write rspamd's configuration.
  7. DNS checks run as a queued job per domain, every 6 h and on demand (1 a minute per domain), through a `DnsResolver` seam; the stored result is what readers get.
  8. SPF is green when its record covers this server through `mx`, `a:<mail_host>` or an `ip4:`/`ip6:` network; `include:` only, or a soft ending without the server, is amber; no SPF is red.
  9. The server's addresses come from `EMAILSQUIRE_SERVER_IPS`, else from `mail_host`'s A and AAAA records.
  10. MTA-STS and autoconfig are information, never red.
  11. Admins and managers set catch-all forwarding only while `doctor`'s `postfix.alias_maps` is OK; targets that lead back into the catch-all are refused; removing one works on any server (the console offers Remove there too). Named forwarding written after a catch-all is checked for loops and swallowed typos as well.
  12. The `enabled` and `sendonly` columns are detected (cached 10 min) and never added by the app; section 10 gives the server changes.
  13. New audit actions: `dkim.generated`, `dkim.activated`, `dkim.retired`, `domain.dns_checked` (on demand only), `forwarding.catch_all_set`, `forwarding.catch_all_removed`, `mailbox.receiving_changed`, `mailbox.sendonly_changed`.
  14. Out of scope: publishing DNS through provider APIs, DMARC report parsing, ARC, BIMI.

Still open (from the brief): nothing for phase 4.

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
- [x] rspamd 3.12 (Trixie) as the installer wires it (`smtpd_milters` and `non_smtpd_milters` on 11332, the guide's `dkim_signing.conf`): it signs mail from local networks with the key the selector map names and re-reads a renamed map without a reload. Verified in the mail-truth stack, phase 4.
- [x] Dovecot 2.4.1: the doveadm HTTP listener and auth socket syntax, the reply shapes and the auth failure delay (section 8). The mailbox password schemes and the disabled-hash approach (section 4.3) were verified in a Docker prototype of the installer's config.
- [x] Passkey package vetted (Oct 11, 2026): `laragear/webauthn` is abandoned and is replaced by `laravel/passkeys` (already a Fortify dependency, now a direct requirement). Its WebAuthn ceremonies run behind the app's JSON API routes; registration, assertions, origin/RP checks, replay, limits and resets have backend tests. The Chromium CDP spec exercises enrollment, passwordless sign-in, step-up and the last-factor refusal at desktop and phone widths; its live run is blocked by the Task 4 sandbox, as recorded in the follow-ups doc.

Upstream issue worth reporting to <https://github.com/Signum/ispmail-workaround-org/issues>: in installer v0.5, `relaying_setup` overrides the sender login map that `postfix_config` sets, and `going_live` leaves the password placeholder in `mariadb-sender-login-maps.cf`.
