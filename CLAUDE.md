# EmailSquire

EmailSquire is an AGPL Laravel 13 / PHP 8.4 + React admin console and mailbox-owner portal for ISPmail Trixie mail servers. It manages the guide's MariaDB tables (`virtual_domains`, `virtual_users`, `virtual_aliases`), which Postfix and Dovecot read directly, alongside its own app schema. Framework-free helpers archive and restore maildirs as `vmail` and manage rspamd DKIM keys and selector maps as `_rspamd`; mailbox authentication uses Dovecot's auth socket, and usage/session operations use its doveadm HTTP API. Read `README.md` for the project overview, `docs/install.md` for installation and operations, `docs/backend-plan.md` for design rules and their reasons, and `docs/i18n-glossary.md` for translations.

## Quick start

- Install PHP 8.4 CLI with `curl`, `intl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite` (OpenAPI introspection), XML support (`dom`, `simplexml`, `xml`, `xmlwriter`) and the standard extensions required by the locked Composer dependencies. Use Composer 2, Node.js 22.12+ with npm, and Docker with Compose. `pcntl` enables the development log watcher; the Linux helpers also need `posix`.
- Clone the repository and install the locked PHP and JavaScript dependencies:

  ```sh
  git clone <repository-url> emailsquire
  cd emailsquire
  composer install
  composer check-platform-reqs
  npm ci
  ```

- In a new checkout, create `.env` from `.env.example` if it does not already exist, then generate the application's encryption key:

  ```sh
  test -e .env || cp .env.example .env
  php artisan key:generate
  ```

- Start the disposable MariaDB 11.8 service. It exposes port `33060`, with development root credentials `root` / `root`, and creates `emailsquire_test` for Pest:

  ```sh
  docker compose up -d mariadb
  ```

  Wait for it to be healthy. For the local app, set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=33060`, `DB_DATABASE=emailsquire`, `DB_USERNAME=root`, `DB_PASSWORD=root` and `EMAILSQUIRE_GUIDE_SCHEMA=mailserver_dev` in your development configuration. Keep development schemas separate from the test schemas. Use `MAIL_MAILER=log` to inspect development mail locally.

- Create the development app database and run its migrations:

  ```sh
  docker compose exec -T mariadb mariadb -uroot -proot -e 'CREATE DATABASE IF NOT EXISTS emailsquire;'
  php artisan migrate
  ```

  Migrations create only app tables. For a disposable local guide schema, load the Trixie fixture through `tests/Support/GuideFixture.php`:

  ```sh
  php artisan tinker --execute="Tests\Support\GuideFixture::load('trixie');"
  ```

  This drops and recreates the configured guide schema; use it only on development/test data. Pest configures `emailsquire_test` and `mailserver_test` through `phpunit.xml`, runs app migrations through its database test trait, and loads guide fixtures in the tests that need them. No manual test fixture load is required.

- Run `composer dev`: it delegates to `php artisan dev`, starting the PHP server, queue listener, Vite and, when `pcntl` is available, Pail's log watcher. Open `http://localhost:8000`. Alternatively, run `php artisan serve` and `npm run dev` in separate terminals; run a queue worker separately if needed. `php artisan emailsquire:create-admin admin@example.org` creates a local console admin and prints its one-time password-setup link. Mailbox login and mail-server operations in the local environment require the real services described in `docs/install.md`; the browser suite supplies its own test stand-ins.
- `composer setup` installs PHP dependencies, creates `.env` only if absent, generates a key, runs `migrate --force`, runs `npm install --ignore-scripts` and builds assets. It needs the database configuration ready first and regenerates `APP_KEY`; use it for a fresh setup. The explicit steps above use `npm ci` for the locked dependency tree.

## Where things live

- `app/MailServer/`: guide models, repositories, scoped queries, address claims, quota, Dovecot clients, helper-task queues, DKIM, DNS and audit actions.
- `app/Doctor/`: server health checks and configuration readers.
- `app/Auth/`: console and mailbox authentication, guards, domain access, invitations, two-factor, step-up support and passkeys.
- `helpers/`: standalone `maildir.php` and `dkim.php`, with their implementation classes in `maildir/` and `dkim/`.
- `deploy/`: systemd helper, queue and scheduler units in `systemd/`; fail2ban configuration in `fail2ban/`. `emailsquire:maildir-tasks` inspects the maildir queue; `--retry=<id>` retries a failed maildir task.
- `resources/js/`: React SPA; screens and colocated tests in `features/`, English/Finnish catalogues in `i18n/{en,fi}/`, API client, OpenAPI export and generated types in `api/`.
- `routes/api.php`: session-authenticated API under `/api/v1`; `routes/web.php` serves the SPA and `routes/console.php` schedules background work.
- `tests/Feature/`, `tests/Unit/`, `tests/Arch/`: Pest tests; `tests/MailTruth/`: real mail-service integration; `tests/Support/`: shared helpers; `tests/fixtures/`: SQL and Vite fixtures; `tests/e2e/`: Playwright flows, seed and service fixtures.
- `scripts/`: release archive build and verification scripts.

## Running tests and checks

- `docker compose up -d mariadb` followed by `vendor/bin/pest` runs the PHP suite on MariaDB, never SQLite. It needs no frontend build: the SPA shell test uses a committed fixture manifest. `composer test` clears the configuration cache and runs `php artisan test`.
- Mail-truth tests (group `mailtruth`, excluded by default) need real Postfix, Dovecot and rspamd in the disposable Trixie container, including its DKIM selector-map directory. Tests that change server configuration restore it in `finally`:

  ```sh
  docker compose --profile mailtruth up -d --build --wait trixie-host
  docker compose exec -T trixie-host vendor/bin/pest --group=mailtruth
  ```

- Frontend checks: `npm run lint` (ESLint), `npm run typecheck` (TypeScript), `npm test` (Vitest), `npm run build` (TypeScript check and production Vite build).
- Playwright: first run `npx playwright install chromium`, then `docker compose up -d mariadb` and `npm run test:e2e`. The script builds the SPA and serves it with `APP_ENV=e2e php artisan serve` on port `8123`, using the committed `.env.e2e`. `tests/e2e/seed.php` resets `emailsquire_e2e`, `mailserver_e2e` and the cache table (including limiters and used codes). It seeds `example.org`, `example.net`, three admins, a pending invitation, `owner@example.org`, forwarding and aliases enabled but external forwarding disabled on `example.org`, and active DKIM selector `es20261001` on both domains. Seeding runs in global setup and again in specs; it refuses other database/environment configurations.
- Browser fixtures: `tests/e2e/fixtures/dns.json` makes `example.org` all green and leaves `example.net` without DMARC or its DKIM record; `tests/e2e/fixtures/postfix/main.cf` allows catch-all forwarding. `tests/e2e/dkim-helper.php` runs the real DKIM helper class over pending tasks with a throwaway key directory. Flows run at desktop (`1280` px) and phone (`390` px) widths; layout and Finnish checks also run at tablet (`820` px). Specs share one database and run with one worker.
- Browser accounts: `admin@example.org` and `fi-admin@example.org` have confirmed two-factor with fixed secrets in `support.ts`; `nofactor-admin@example.org` has none. Their password is `Summersun-e2e-2026!`. `signIn()` uses `freshCode()`, which never reuses a 30-second period per secret. Ordinary step-up flows use the password. `OWNER` / `signInOwner()` signs in as `owner@example.org`; all e2e mailboxes use `summersun`, also the SQL fixture password. Mailbox sign-in leaves the shared `login` bucket intact (five attempts/minute per address and IP), so keep specs within it. Mail is logged to `storage/logs/e2e-mail.log`; `readMailLink()` reads only mail logged since the last `seed()`.
- Passkey flows use `addVirtualAuthenticator()` in `support.ts`: a Chromium CDP CTAP2 internal authenticator with resident keys, automatic presence and `isUserVerified: true`. They run at desktop and phone widths, skip other browser engines and seed before each test. Domain-delete step-up in `passkeys.spec.ts` uses the passkey.
- Regenerate the committed OpenAPI document and TypeScript types after API changes:

  ```sh
  php artisan emailsquire:openapi
  npm run api:types
  ```

  `OpenApiTest` checks route coverage and export freshness; CI also compares a fresh export and generated `resources/js/api/schema.d.ts`. The export uses an empty in-memory SQLite connection for type introspection, not for application tests.
- Regenerate the audit-action JSON exactly as `AuditActionsExportTest` specifies:

  ```sh
  php -r 'require "vendor/autoload.php"; file_put_contents("resources/js/api/auditActions.json", json_encode(App\MailServer\AuditActions::GROUPS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");'
  ```

  Add translated activity text for new actions in `resources/js/features/dashboard/activityText.ts` and both catalogues; Vitest checks action coverage.
- CI (`.github/workflows/ci.yml`) runs `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `vendor/bin/psalm --taint-analysis --no-cache`, `composer audit`, the OpenAPI export diff, `npm audit --audit-level=high`, lint, typecheck, Vitest, the generated-types diff and the production build. Separate jobs run Pest, Chromium Playwright (`npx playwright install --with-deps chromium`) and mail-truth. On `main`, after those gates pass, CI runs `scripts/build-release.sh` and `scripts/verify-release.sh dist/*.tar.gz`.

## Rules the code must keep

- Write guide tables only through `App\MailServer\Repositories\*`. Controllers never call `save`, `update`, `delete`, `insert` or the query builder on guide models.
- Preserve lock order: for address-specific writes, acquire `SourceLock` around the transaction, then any domain lock, mailbox locks and alias locks, in that order. Bulk address locks are sorted. Mailbox creation and single/bulk quota changes lock the domain `FOR UPDATE` before counting `DomainLimits`; writes adding other rows under a domain take a shared domain lock. Domain deletion locks the domain, mailboxes and aliases in order. Portal forwarding takes all required mailbox locks before its alias locking read, validates expansion with current alias locking reads, and retries deadlocks at most three times. AliasExpansion always reads mailbox existence without locks throughout the walk, so no later step takes a mailbox lock after an alias lock; alias precedence is preserved. Do not introduce reverse lock ordering.
- Run no DDL against the guide schema outside `tests/Support/GuideFixture.php`. Migrations touch the app schema only. `emailsquire:import-ima --drop` may drop `blacklist_email`, the legacy ISPmail Admin table, not one of the guide's tables.
- Keep `helpers/` framework-free: no Composer autoload, `Illuminate\*`, `App\*`, process execution or `.env` reads. Trust no task-row path. Derive paths only from helper options (`--mail-root`, `--trash-dir`; `--dkim-dir`, `--selector-map`) and validated address/domain/selector components, checking paths with `lstat` and refusing symlinks. Helpers run only as their data directory's owner; root cannot bypass the ownership check.
- Generate DKIM keys with PHP OpenSSL (RSA 2048, mode `0600`), never `rspamadm` or another process. Rewrite only the affected domain's selector-map line through a temp file and `rename()` in the map's directory, `/etc/rspamd/emailsquire` (`root:_rspamd 0770`). `_rspamd` must not be able to write `/etc/rspamd` itself. `helper_tasks.result` contains public data only; validate RSA public keys and payload selectors before `Dkim::sync()` applies results. Activation requires the latest DNS check to show that exact key or an explicit force; retirement never removes the active key and waits 15 minutes after the latest activation. Deleted domains' key files remain for operators to remove.
- Preserve the guide row's spelling in `helper_tasks.address`; helpers never normalise it. Dovecot's home follows the stored spelling: `Anna@Example.org` lives in `/var/vmail/Example.org/Anna`. `archivePending()` and `restorePending()` match case-insensitively.
- Root must never run plain `php artisan` in an installed release. Use the shipped root-owned, executable `scripts/root-artisan`, which overrides all five Laravel bootstrap cache paths and `VIEW_COMPILED_PATH` with a fresh root-only temporary directory and removes it on exit. Keep `scripts/` in release artifacts.
- Restrict the doveadm API key with exactly `doveadm_allowed_commands = "quota get" kick user` (space-separated, with `"quota get"` quoted). Doctor must first verify `doctor-probe@invalid` is absent (user lookup exit code 67), then verify a metadata-only `fetch` probe (`field: [guid]`, a random 32-hex mailbox search) answers command-level `unAuthorized`. If the probe user exists, fail without fetching. Transport, HTTP-auth and invalid-request failures must retain their own diagnostics; a working user lookup alone is insufficient. Keep HTTP key rejection distinct from command authorization denial.
- Execute no processes in `app/`: no `exec`, `shell_exec`, `system`, `passthru`, `proc_open`, `popen` or `Symfony\Component\Process`. Tests and `scripts/` may execute processes. Use `env()` only in `config/`.
- Express quota in bytes as `quota_bytes`. Database `0` means `quota_default_bytes` (5 GiB); clients cannot send `0`. Accept 1 MiB through 1 TiB.
- Normalise new addresses to lowercase ASCII, with IDN domains in punycode. Limit domains to 50 and addresses to 100 characters. Mailboxes and forwarding sources cannot contain `+`; forwarding targets may through `AddressNormalizer::target()`.
- Store mailbox passwords as `{BLF-CRYPT}` with bcrypt cost 12. Require at least 12 characters, at most 72 bytes, no NUL and strength score at least 3; apply the HIBP check when enabled. Only the password-reset response returns plaintext, once, with `Cache-Control: no-store`. Never put passwords in logs, audit, sessions or exception context.
- Keep two guards and only one per session: `web` for console users, `mailbox` for portal `MailboxPrincipal`. `POST /auth/login` checks the console account first, then the mailbox via Dovecot `PasswordChecker`; `mailbox: true` skips the console check. Successful login answers `{kind}` (or the pending two-factor challenge). Console routes require `auth:web`, `/portal/*` requires `auth:mailbox`, and the other session kind gets 401. The SPA keeps portal sessions in `/my/*` and console sessions out of it. `SingleGuardSession` signs the other guard out.
- Bind `FakeDovecotAuthClient` (`password_verify` on `{BLF-CRYPT}`) and `FakeDnsResolver` (`tests/e2e/fixtures/dns.json`) only in `APP_ENV=e2e`; both throw elsewhere. Pest Feature tests bind `Tests\Support\FakePasswordChecker` and an initially empty `Tests\Support\StubDnsResolver`. Mail-truth uses real Dovecot/DNS. Never bind test fakes in local or production; doctor's `runtime.test_environment` fails e2e or testing with `/var/vmail`.
- Portal owners write only under their domain's `SelfService` switches (forwarding, aliases, external forwarding): disabled features answer 403 `self_service_disabled`. Allow at most 10 forwarding targets; external targets require the switch or answer 422 `external_forwarding_disabled`. Allow at most 20 aliases on the owner's own domain, delivering only to that mailbox. Use `AddressClaims`, reserved locals in `emailsquire.owner_reserved_locals` and the catch-all ownership rule for address claims. Refused claims all answer 422 `address_unavailable`, without revealing the reason; validation and limit errors retain their own codes. Owners cannot claim an alias under a catch-all unless it delivers only to them. Limits: `login` five/minute per address and IP; `mailbox-login` five/minute per address and IP plus 30/minute per IP; `mailbox-password` six/minute per mailbox; `portal-writes` 30/minute per mailbox.
- Console TOTP uses Fortify with window 1. `TwoFactorCodeProvider` accepts each code once per secret using an atomic cache marker containing an HMAC, never the secret/code. `emailsquire.require_two_factor` is `admins` by default, `everyone`, or `off` for tests only. Without a second factor, required users get 403 `two_factor_required` except on `/me`, `/me/two-factor*`, `/me/passkeys*`, `/me/recovery-codes`, `/me/password` and `/auth/*`.
- Confirmed TOTP or a usable (not disabled) passkey counts as a second factor. Passkeys require `userVerification: required`; passwordless sign-in satisfies required 2FA. Keep `laravel/passkeys` behind app routes, with RP ID from `APP_URL`'s host and the allowed origin exactly its scheme/host/port. Challenges expire after five minutes and are single-use through an atomic cache nonce. Refused assertions answer uniform 422 `passkey_failed`; a detected clone disables the passkey and answers 422 `passkey_cloned`. `passkey-login` allows 10/minute per IP across options/assertions. Limit users to 10 passkeys, including disabled ones. Removing the last second factor while required answers 409 `last_second_factor`. Both operator and admin two-factor resets delete passkeys and recovery codes. Renaming needs no step-up; adding/removing does.
- Dangerous writes require step-up: 423 `step_up_required` unless `POST /auth/confirm-password` succeeded with a password, TOTP code or passkey within 300 seconds. Protect domain deletion, DKIM task requests, two-factor setup/confirmation/removal, recovery-code access/regeneration, passkey addition/removal, access changes, invitation creation/resend/revocation, user deletion, grant changes and admin two-factor reset. `PATCH /users/{user}` also checks step-up for email, role or status fields; a name alone needs none. Run authorization before step-up so out-of-scope resources stay 404.
- Encrypt TOTP secrets and recovery codes with `APP_KEY`. In the SPA, keep plaintext secrets, recovery codes and returned passwords only in the displaying component's state, never query/mutation caches, storage, URLs, toasts or logs. Token-carrying mail jobs implement `ShouldBeEncrypted`; every mailable is queued, served in production by `emailsquire-queue.service`. Never log tokens or include them in exception context.
- Catch-all (`@domain`, `PUT|DELETE …/forwarding/@`) is for admins/managers, never owners. PUT requires `CatchAllSupport` (doctor's `postfix.alias_maps`: email2email in `virtual_alias_maps`, read as the app user, cached 10 minutes), else 409 `catch_all_unsupported`. DELETE works on any server. A catch-all keeps no copy; targets that lead back to it, including an unknown address on its own domain or an `AliasExpansion` loop, get 422 on `targets.N`.
- `SchemaExtension` detects `virtual_users.enabled` and `sendonly`, cached 10 minutes, and reports `/me` capabilities. The app never adds these columns. Without a requested column, PATCH `receiving`/`sendonly` answers 422 `schema_extension_missing` and changes nothing.
- Every non-2xx API response under `/api/v1` uses `application/problem+json` with a stable snake_case `code`. Paginated lists return `{data, meta: {page, per_page, total, last_page}}`, with page size default 10 and maximum 200. Follow each endpoint's input contract: mailbox/forwarding lists use `limit`; some lists accept `per_page` too.
- Helper task types are `maildir.archive`, `maildir.restore`, `dkim.generate`, `dkim.activate`, `dkim.retire`, `dkim.inspect`. Keep audit actions in exactly one `AuditActions::GROUPS` group and the committed `resources/js/api/auditActions.json` in sync. Record audit rows in the change's transaction; only `admin.create` and `admin.setup` may be recorded outside one. Never audit a secret, password, code, token, credential or challenge.
- `AuditVisibility` governs `/audit-log`, its actors and dashboard activity: server admins see every row; others see only rows in their `DomainScope`, excluding `admin.*`, `maildir.*` and `user.*` except `user.invited`.
- Welcome email (`POST …/mailboxes/{mailbox}/welcome`) contains settings only, never the password. Use `emailsquire.mail_host` (`EMAILSQUIRE_MAIL_HOST`, default hostname) for IMAP 993 and SMTP 587, and optional `emailsquire.webmail_url` (`EMAILSQUIRE_WEBMAIL_URL`). Limit queued welcomes to 10/hour per user.
- Use `DomainAccess` as the sole role source (`admin`, `manager`, `viewer`, none); domain-scoped lists use `DomainScope` from `DomainAccess::scope()`. Out of scope is 404; in scope but forbidden is 403. Users API is 404 to everyone except server admins.
- Console credential failures (unknown, deactivated and setup-pending accounts) and forgotten-password no-mail paths perform exactly one dummy verification without generating a hash. The `users` and `admin_setup` database token brokers verify once on all setup/reset failures: real checks for live token rows, dummy checks for unknown users or absent/expired rows. Precomputed dummy payloads use encoded work factors matching the configured bcrypt/Argon driver and cost; never warm up a dummy hash with `Hash::make()`.
- Deactivated console users cannot sign in: return the wrong-password answer after the same hash check. Keep their grants but give them no role. Existing sessions answer 401 and end on the next request through `EnsureActiveUser`.
- Invitation links use `{APP_URL}/invite#{token}`. Accept tokens only in POST bodies to `/auth/invitations/lookup` and `/auth/invitations/accept`; never put them in server-visible URLs, logs, audit or query keys. Store only SHA-256 token hashes. Acceptance must compare the presented token hash with the current locked invitation row on every transaction attempt; rotation by resend answers the same 404 `invitation_not_found` as an unknown token.
- Use English as default/fallback and support Finnish. Put every user-visible SPA string in `resources/js/i18n/{en,fi}/`, including JSX text, accessible labels, titles, placeholders, alt text, toasts and `problemMessage()` output. ESLint's `i18next/no-literal-string` checks JSX and the props listed in `eslint.config.js`; review `.ts` strings and object literals, and add new user-visible prop names to the lint rule. Use `<Trans>` only with validated addresses/domain names/formatted numbers; render free text through `t()`. Keep keys and placeholders identical across languages. Server messages live in `lang/{en,fi}`, chosen by the user's locale, then `Accept-Language`, then English.
- Use EmailSquire as the product name and `emailsquire` in technical identifiers. Preserve the guide's database username `mailadmin`.

### Audit actions

The complete list comes from `app/MailServer/AuditActions.php`, grouped by `AuditActions::GROUPS`. Entries below specify `changes` when present; otherwise it is `null`. Mailbox actions use object type `mailbox`, key the address and the domain; forwarding actions use `forwarding`, key the source and the domain; domain actions use `domain`, key/name the domain. Account actions use `user`, key the email, without a domain. Portal actors use `actor_type=mailbox` and their stored address as `actor_id`.

`mailboxes`:

- `mailbox.create`: `{quota_bytes, display_name}`.
- `mailbox.update`: changed `quota_bytes` / `display_name` fields as `{field: {old, new}}`.
- `mailbox.receiving_changed`: `{old, new}` booleans, only on a real change.
- `mailbox.sendonly_changed`: `{old, new}` booleans, only on a real change.
- `mailbox.password_reset`.
- `mailbox.disable`.
- `mailbox.enable`.
- `mailbox.delete`: `{forwarding_removed}` (count of same-domain forwarding sources removed).
- `mailbox.welcome_sent`: `{to}`.
- `maildir.restore`: `{from}` (archive timestamp), object `mailbox`.
- `maildir.retry`: `{type, address}`, object `helper_task`, key task ID and its domain.
- `portal.password_changed`: object `mailbox`.
- `portal.forwarding_changed`: `{old, new, keep_copy: {old, new}}`, object `forwarding`.
- `portal.alias_created`: `{destination}`, object `forwarding`.
- `portal.alias_deleted`: `{destination}`, object `forwarding`.

`forwarding`:

- `forwarding.replace`: `{old, new, keep_copy: {old, new}}` (target lists and booleans).
- `forwarding.delete`: `{removed, old, new: [], keep_copy: {old, new}}`.
- `forwarding.catch_all_set`: `{old, new}` target lists.
- `forwarding.catch_all_removed`: `{removed, old, new: []}`.

`domains`:

- `domain.create`.
- `domain.delete`: `{mailboxes, forwarding, incoming_forwarding_removed}` counts.
- `domain.limits_changed`: changed `max_mailboxes` / `max_quota_bytes_total` as `{field: [old, new]}`.
- `domain.self_service_changed`: changed `forwarding` / `aliases` / `external_forwarding` as `{field: [old, new]}`.
- `domain.dns_checked`: on-demand requests only, never scheduled checks.
- `dkim.generated`: recorded when generation is queued.
- `dkim.activated`: `{selector, forced}` when activation is queued; `forced` means the key was not published.
- `dkim.retired`: `{selector}` when retirement is queued.

`blocked`:

- `blocked.create`: `{reason}`, object `blocked_address`, key the address and its domain.
- `blocked.delete`: object `blocked_address`, key the address and its domain.

`access`:

- `grant.created`: `{role}`, object `user`, key email and the granted domain.
- `grant.changed`: `{role: [old, new]}`, object `user`, key email and the granted domain.
- `grant.removed`: `{role}`, object `user`, key email and the granted domain.
- `invitation.resent`: `{role}`, object `invitation`, key invited email and optional domain.
- `invitation.revoked`: `{role}`, object `invitation`, key invited email and optional domain.
- `invitation.accepted`: `{role}`, object `invitation`, key invited email and optional domain.
- `user.invited`: `{role}`, object `invitation`, key invited email and optional domain.

`account`:

- `user.email_changed`: `{email: [old, new]}`, key the new email.
- `user.role_changed`: `{is_server_admin: [old, new]}`, plus `grants_removed` when promotion removes grants and `invited_by` (inviter email) for invitation-based promotion.
- `user.deactivated`.
- `user.reactivated`.
- `user.deleted`.
- `user.password_changed`.
- `user.password_reset`.
- `user.sessions_revoked`: `{count}`.
- `user.two_factor_enabled`: confirmed setup only.
- `user.two_factor_disabled`: confirmed removal or operator reset.
- `user.two_factor_reset`: admin reset.
- `user.recovery_codes_regenerated`.
- `user.passkey_added`: `{name}`.
- `user.passkey_removed`: `{name}`.
- `user.passkey_renamed`: `{name: [old, new]}`.
- `admin.create`: `{created, reset}` booleans.
- `admin.setup`.

## Contributing conventions

- Use conventional commit subjects, such as `fix: reject a forwarding loop` or `feat: add a domain check`.
- Write tests first for behaviour changes. Cover authorization, scope, failure cases and mail-server effects where relevant.
- Add every user-visible string to both `en` and `fi` with identical keys and placeholders. Read `docs/i18n-glossary.md` before changing Finnish catalogues or `lang/fi`; add new terms there first. Address the reader as "sinä", never "te".
- Keep problem `code`s, audit action names, Artisan and doctor output, logs and OpenAPI in English.
- Update `docs/install.md` and `docs/backend-plan.md` alongside behaviour changes; regenerate API/types and audit exports when their sources change.
