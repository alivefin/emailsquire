# Install and operate EmailSquire

This guide adds EmailSquire to a working ISPmail server on Debian Trixie. You keep the guide's Postfix, Dovecot 2.4, MariaDB and rspamd installation. EmailSquire uses the existing `virtual_domains`, `virtual_users` and `virtual_aliases` tables and creates its own schema beside them. Laravel migrations change only the app schema.

The examples use `mailserver` for the existing schema, `emailsquire` for the app schema, `admin.example.org` for the console and `mail.example.org` for IMAP and SMTP. Replace the example names, release identifier and all `REPLACE_...` values before running commands. No example password is suitable for use. Shell commands on the mail host are run as root unless they explicitly use `runuser`; build commands are run as your build user on a separate machine.

The deployment layout comes from [backend-plan.md](backend-plan.md), and the mail-service paths and edits match `docker/trixie-host/`. The repository supplies the queue, scheduler and helper units. It does not supply a complete PHP-FPM or Apache configuration; the examples below need validation on your host. The ISPmail installer (`ispmail.sh` v0.5) already runs Apache with certbot on ports 80/443 and Roundcube under mod_php. EmailSquire gets its own Apache vhost and FPM master; leave Roundcube's vhost and PHP handler alone. Your backup destination is a local choice.

## 1. Check requirements

1. Confirm that you already run the Trixie ISPmail stack. Its package names are `mariadb-server`, `postfix`, `postfix-mysql`, `dovecot-core`, `dovecot-imapd`, `dovecot-lmtpd`, `dovecot-mysql` and `rspamd`. This guide does not reinstall the mail stack. Restored Bookworm data on a Trixie server is supported, including legacy password hashes and a missing domain-name unique index; doctor reports these.

2. Install the application runtime and web-server packages you are missing:

   ```sh
   apt-get update
   apt-get install php8.4-fpm php8.4-cli php8.4-mysql php8.4-intl \
       php8.4-mbstring php8.4-xml php8.4-curl apache2 certbot \
       python3-certbot-apache fail2ban ca-certificates
   php -v
   php -m
   ```

   You need PDO MySQL, OpenSSL and POSIX in CLI PHP for the helpers, and the Laravel extensions in FPM. The test image installs the CLI packages above; FPM is listed in the backend plan. The project's PHP requirement is `^8.4`. Builds use Composer 2 and Node.js 22, as in CI; use at least 22.12 for the locked Vite dependencies. Neither is needed on the mail host when you deploy a built release.

3. Reserve a separate HTTPS origin for EmailSquire, apart from Roundcube. Passkeys require a secure browser origin, and the session cookie is Secure by default. `APP_URL` must name the exact HTTPS origin you will visit. Point its DNS at this server; step 6 obtains a certificate through the existing certbot setup before enabling the HTTPS vhost.

4. Check the existing users, directories and socket:

   ```sh
   getent passwd vmail _rspamd
   stat /var/vmail /var/lib/rspamd/dkim
   ls -l /run/mysqld/mysqld.sock
   ```

   Mail homes are `/var/vmail/<domain>/<local>/Maildir`; the helper archives the entire `<local>` home, including Sieve files. `/var/vmail` belongs to `vmail:vmail` (the test host uses `0750`), and the DKIM directory belongs to `_rspamd:_rspamd`. Do not give the app user access to mail contents or DKIM private keys. Keep the host clock synchronised for TOTP and task timestamps. Allow system DNS lookups and, if you enable HIBP checks, outbound HTTPS.

## 2. Create the OS user and prepare database access

1. Create the application user and deployment directories:

   ```sh
   useradd --system --user-group --no-create-home --shell /usr/sbin/nologin emailsquire
   install -d -o root -g root -m 0755 /opt/emailsquire/releases
   install -d -o root -g emailsquire -m 0750 /etc/emailsquire
   install -d -o root -g root -m 0755 /var/lib/emailsquire
   install -d -o emailsquire -g emailsquire -m 0750 /var/lib/emailsquire/storage
   install -d -o emailsquire -g emailsquire -m 0750 /var/lib/emailsquire/cache
   ```

   If `emailsquire` already exists, inspect it rather than re-creating it. PHP-FPM, the Laravel queue and scheduler run as this user. The maildir helper runs as `vmail`; the DKIM helper runs as `_rspamd`.

2. As the MariaDB administrator, create only the empty app schema:

   ```sh
   mariadb <<'SQL'
   CREATE DATABASE emailsquire CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   SQL
   ```

   Use a single MariaDB instance and a single runtime connection: its default schema is `emailsquire`, and the guide tables are schema-qualified with `EMAILSQUIRE_GUIDE_SCHEMA`. This lets transactions span both schemas. Do not reuse the guide's `mailadmin` account or grant schema-wide privileges to the app. The app needs no DDL rights.

3. Plan the accounts now, but generate and apply their grants after migrations in step 5. `emailsquire:grants` refuses to emit SQL unless it can see all the required app tables. Its defaults are:

   | Account | Authentication and access |
   | --- | --- |
   | `emailsquire_app@localhost` | Password from `DB_PASSWORD`; `SELECT, INSERT, UPDATE, DELETE` on the three guide tables and ordinary app tables, `INSERT, SELECT` on `audit_log`, and `SELECT` on `migrations`. Grants are per table. |
   | `vmail@localhost` | `unix_socket`, no password; only `SELECT (email)` on `mailserver.virtual_users`, plus task reads and updates to the result/status columns. |
   | `_rspamd@localhost` | `unix_socket`, no password; `SELECT` on `mailserver.virtual_domains`, plus task reads and updates to the result/status columns. |
   | `root@localhost` via `migrator` | Debian's root socket authentication, used by root-run migrations and grant generation; never the runtime connection. |

   Both helpers receive `SELECT` and `UPDATE (status, attempts, last_error, note, result, run_after, updated_at)` on `emailsquire.helper_tasks`. They cannot rewrite task instructions, insert tasks or write `applied_at`. Their MariaDB account names must match their effective OS user names.

   Check any existing accounts with `SHOW GRANTS`. `CREATE USER IF NOT EXISTS` neither resets their passwords/authentication nor removes excess grants. Prefer Debian's passwordless root socket authentication. If it is unavailable, use the separate root-controlled procedure in step 5 to supply administrator credentials only to the command that needs them. Never put a privileged password in the runtime `.env` or configuration cache.

## 3. Get the code and build a release

1. On your build machine, obtain the intended revision:

   ```sh
   git clone https://github.com/alivefin/emailsquire.git
   cd emailsquire
   git switch --detach REPLACE_WITH_REVIEWED_TAG_OR_COMMIT
   composer install --no-dev --classmap-authoritative --no-interaction
   npm ci && npm run build
   ```

   `npm run build` runs TypeScript checking and Vite. The result must include `vendor/autoload.php` and `public/build/manifest.json`. Do not run `composer setup` on the mail server: that development script also creates configuration, generates a key and runs migrations.

2. Build and verify the deployment tarball with the repository's scripts:

   ```sh
   scripts/build-release.sh
   scripts/verify-release.sh dist/*.tar.gz
   ```

   The build script rebuilds from committed `HEAD`, installs production Composer dependencies and builds the SPA; it does not package working-tree edits. CI's `release` job uploads the same tarball as the `emailsquire-release` artifact on `main`. The repository does not establish a published release version or permanent binary download URL. Obtain the chosen artifact through your deployment process and transfer it to the host. Build without production secrets; configuration caches are generated on the host.

3. On the mail host, extract into a new release directory. The script's archive has its files at the archive root:

   ```sh
   EMAILSQUIRE_VERSION=REPLACE_WITH_RELEASE_ID
   EMAILSQUIRE_RELEASE=/opt/emailsquire/releases/$EMAILSQUIRE_VERSION
   install -d -o root -g root -m 0755 "$EMAILSQUIRE_RELEASE"
   tar -xzf /tmp/emailsquire-release.tar.gz -C "$EMAILSQUIRE_RELEASE"
   chown -R root:root "$EMAILSQUIRE_RELEASE"
   find "$EMAILSQUIRE_RELEASE" -type d -exec chmod 0755 {} +
   find "$EMAILSQUIRE_RELEASE" -type f -exec chmod 0644 {} +
   chmod 0755 "$EMAILSQUIRE_RELEASE/scripts/root-artisan"
   ```

   Use a new directory each time. Root owns the code, and service users can only read it. Directories `0755` and code files `0644` let `vmail` and `_rspamd` traverse the release and read their helpers.

4. For this first installation, initialise persistent storage and move bootstrap cache outside the code tree:

   ```sh
   cp -a "$EMAILSQUIRE_RELEASE/storage/." /var/lib/emailsquire/storage/
   mv "$EMAILSQUIRE_RELEASE/storage" "$EMAILSQUIRE_RELEASE/storage.dist"
   ln -s /var/lib/emailsquire/storage "$EMAILSQUIRE_RELEASE/storage"
   mv "$EMAILSQUIRE_RELEASE/bootstrap/cache" "/var/lib/emailsquire/cache/$EMAILSQUIRE_VERSION"
   ln -s "/var/lib/emailsquire/cache/$EMAILSQUIRE_VERSION" "$EMAILSQUIRE_RELEASE/bootstrap/cache"
   chown -R emailsquire:emailsquire /var/lib/emailsquire/storage /var/lib/emailsquire/cache
   chmod -R go-rwx /var/lib/emailsquire/storage /var/lib/emailsquire/cache
   ln -s /etc/emailsquire/.env "$EMAILSQUIRE_RELEASE/.env"
   ln -s "$EMAILSQUIRE_RELEASE" /opt/emailsquire/current
   ```

   Each release gets its own cache directory. Cached configuration contains secrets, so it must not be world-readable. Shared storage keeps logs, compiled views and runtime files across releases. `storage.dist` holds only the release's original storage skeleton.

## 4. Configure `.env`

1. On a fresh installation only, create `/etc/emailsquire/.env` from the example, then edit it as root. If it already exists, edit it without copying over it:

   ```sh
   install -o root -g emailsquire -m 0640 /opt/emailsquire/current/.env.example /etc/emailsquire/.env
   ```

   The release's `.env` symlink points here. The file remains `root:emailsquire 0640`, inaccessible to both helpers and Apache's `www-data`. Fill in at least these values:

   ```dotenv
   APP_NAME=EmailSquire
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://admin.example.org
   APP_KEY=
   APP_LOCALE=en
   APP_FALLBACK_LOCALE=en
   APP_MAINTENANCE_DRIVER=file

   DB_CONNECTION=mysql
   DB_SOCKET=/run/mysqld/mysqld.sock
   DB_HOST=localhost
   DB_PORT=3306
   DB_DATABASE=emailsquire
   DB_USERNAME=emailsquire_app
   DB_PASSWORD=REPLACE_WITH_APP_DB_PASSWORD
   DB_CHARSET=utf8mb4
   DB_COLLATION=utf8mb4_unicode_ci
   EMAILSQUIRE_GUIDE_SCHEMA=mailserver

   SESSION_DRIVER=database
   SESSION_LIFETIME=30
   SESSION_COOKIE=__Host-emailsquire
   SESSION_SECURE_COOKIE=true
   SESSION_HTTP_ONLY=true
   SESSION_SAME_SITE=strict
   SESSION_PATH=/
   SESSION_DOMAIN=null
   SESSION_ENCRYPT=false
   CACHE_STORE=database
   QUEUE_CONNECTION=database
   DB_QUEUE_RETRY_AFTER=150
   FILESYSTEM_DISK=local

   LOG_CHANNEL=stack
   LOG_STACK=single
   LOG_LEVEL=info
   MAIL_MAILER=smtp
   MAIL_HOST=127.0.0.1
   MAIL_PORT=25
   MAIL_FROM_NAME="EmailSquire"
   EMAILSQUIRE_MAIL_FROM=emailsquire@mail.example.org
   EMAILSQUIRE_MAIL_HOST=mail.example.org
   EMAILSQUIRE_WEBMAIL_URL=https://webmail.example.org/
   EMAILSQUIRE_SUPPORT_CONTACT="Your support team, help@example.org"
   EMAILSQUIRE_SERVER_IPS=192.0.2.10,2001:db8::10

   EMAILSQUIRE_HIBP=true
   EMAILSQUIRE_REQUIRE_TWO_FACTOR=admins
   EMAILSQUIRE_MANAGERS_CAN_SHARE=false
   DOVEADM_URL=http://127.0.0.1:8080/doveadm/v1
   DOVEADM_API_KEY=REPLACE_WITH_RANDOM_DOVEADM_KEY
   DOVECOT_AUTH_SOCKET=/run/dovecot/emailsquire-auth
   ```

2. Generate the first application key without having Artisan edit the configuration:

   ```sh
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan key:generate --show
   ```

   Put the printed value into `APP_KEY` as root. Preserve it across releases and backups. It encrypts TOTP secrets, recovery codes and sensitive queued mail jobs. `APP_PREVIOUS_KEYS` accepts comma-separated old keys for Laravel decryption during rotation. Do not generate a new key as an upgrade step.

   Passkey user handles use `PASSKEYS_USER_HANDLE_SECRET`, which defaults to `APP_KEY`. This optional setting lets you keep handles stable when rotating `APP_KEY`. Before rotation, preserve the existing `PASSKEYS_USER_HANDLE_SECRET` unchanged; if it has never been set, set it to the exact current `APP_KEY` string, including its `base64:` prefix, before replacing `APP_KEY`. Do not substitute a new random value for existing credentials. On a fresh installation you may choose a separate strong secret before enrolling any passkeys. Back it up and keep it stable across releases and restores. Rebuild config cache after setting it, and test account recovery and existing TOTP/passkeys before removing an old encryption key.

3. Review what the settings do:

   | Keys | Meaning |
   | --- | --- |
   | `APP_NAME`, `APP_ENV`, `APP_DEBUG` | Name, environment and error disclosure. Use `production` and `false`; the web app refuses debug mode in production. Never use `e2e` or `testing` on the mail server. |
   | `APP_URL` | URL for setup, reset and invitation links. Its host is the passkey RP ID; its scheme, host and port are the sole allowed passkey origin. Use one stable origin, with no application subdirectory. A hostname change affects enrolled passkeys. |
   | `APP_LOCALE`, `APP_FALLBACK_LOCALE` | English (`en`) or Finnish (`fi`); English is the default/fallback. Users and browsers can choose their language. `APP_FAKER_LOCALE` is for development fixtures. |
   | `APP_MAINTENANCE_DRIVER`, `APP_MAINTENANCE_STORE` | Use the file driver for this single-host layout. The store matters only for cache maintenance mode. |
   | `DB_CONNECTION`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Runtime connection and app schema; guide reads and writes use the same connection. Use `mysql` with MariaDB. |
   | `DB_SOCKET`, `DB_HOST`, `DB_PORT` | A non-empty socket selects the local Unix socket here; otherwise host/port select TCP. The grants' account host must match the connection. Leave `DB_URL` unset so it cannot override these settings. |
   | `DB_CHARSET`, `DB_COLLATION` | Keep `utf8mb4` / `utf8mb4_unicode_ci`. Maildir task matching relies on a case-insensitive collation. `MYSQL_ATTR_SSL_CA` is for an alternative TLS DB connection, not this socket layout. |
   | `DB_MIGRATOR_SOCKET`, `DB_MIGRATOR_USERNAME`, `DB_MIGRATOR_PASSWORD` | The `migrator` connection defaults to `/run/mysqld/mysqld.sock`, `root` and an empty password; its schema comes from `DB_DATABASE`. Leave these unset in runtime `.env`. Alternative administrator credentials belong only in the root-controlled command environment in step 5. `config:cache` serialises every connection, even unused ones, so never run it with privileged passwords in the environment. |
   | `EMAILSQUIRE_GUIDE_SCHEMA` | Existing guide schema, default `mailserver`; there is no separate guide connection. Match helper `--guide-schema` if you change it. |
   | `SESSION_DRIVER`, `SESSION_LIFETIME`, `SESSION_ENCRYPT` | Database sessions, 30-minute idle expiry, optional session-payload encryption. The app also enforces 12-hour console and 2-hour portal absolute limits. |
   | `SESSION_COOKIE`, `SESSION_SECURE_COOKIE`, `SESSION_HTTP_ONLY`, `SESSION_SAME_SITE`, `SESSION_PATH`, `SESSION_DOMAIN` | Keep `__Host-emailsquire`, Secure, HttpOnly, Strict, `/`, and no Domain. These require HTTPS. Keep `SESSION_EXPIRE_ON_CLOSE` at default `false` unless you want browser-close expiry. |
   | `SESSION_CONNECTION`, `SESSION_TABLE`, `SESSION_STORE`, `SESSION_PARTITIONED_COOKIE` | Leave unset/default: app connection, `sessions`, no separate store, no partitioned cookie. A different session table needs matching migrations/grants. |
   | `CACHE_STORE`, `CACHE_PREFIX` | Database cache supports rate limits, single-use codes/challenges and capabilities. Optional prefix, default derived from `APP_NAME`; keep it stable. Leave `DB_CACHE_CONNECTION`, `DB_CACHE_TABLE`, `DB_CACHE_LOCK_CONNECTION`, `DB_CACHE_LOCK_TABLE` at defaults for the app's cache/lock tables. |
   | `QUEUE_CONNECTION`, `DB_QUEUE_RETRY_AFTER` | Database jobs sent by the queue service. Retry-after defaults to 150 seconds and must exceed the DNS job's 100-second timeout. |
   | `DB_QUEUE_CONNECTION`, `DB_QUEUE_TABLE`, `DB_QUEUE`, `QUEUE_FAILED_DRIVER` | Leave default app connection, `jobs`, `default`, `database-uuids`; failed jobs go to `failed_jobs`. |
   | `FILESYSTEM_DISK` | Keep `local`; it is app storage, not Maildir access. No public storage symlink is required for the SPA. |
   | `LOG_CHANNEL`, `LOG_STACK`, `LOG_LEVEL` | `stack` with `single` writes `storage/logs/laravel.log`, the shipped fail2ban path. Include warning-level messages and arrange log rotation. `daily` uses dated files (`LOG_DAILY_DAYS`, default 14), requiring a jail logpath change. |
   | `LOG_DEPRECATIONS_CHANNEL`, `LOG_DEPRECATIONS_TRACE` | Default null channel and no trace; optional deprecation logging. Avoid exposing exception arguments. |
   | `MAIL_MAILER`, `MAIL_HOST`, `MAIL_PORT` | SMTP to local Postfix at `127.0.0.1:25`; every app email is queued. The SMTP transport disables automatic STARTTLS. |
   | `MAIL_SCHEME`, `MAIL_URL`, `MAIL_USERNAME`, `MAIL_PASSWORD` | Leave unset for local SMTP without auth or TLS. The sources specify this local transport, not a remote relay deployment. |
   | `MAIL_EHLO_DOMAIN`, `MAIL_FROM_NAME` | EHLO defaults to `APP_URL`'s host; sender display name defaults to `APP_NAME`. |
   | `EMAILSQUIRE_MAIL_FROM` | Actual sender for all mail, overriding Laravel's from address. Default `emailsquire@<hostname>`; choose a valid address/domain whose delivery and SPF/DKIM you have checked. Doctor does not validate this address. |
   | `EMAILSQUIRE_MAIL_HOST` | Mail client hostname on your TLS certificate, default this host's name. Welcome mail/portal show IMAP 993 with TLS and SMTP 587 with STARTTLS; DNS checks use it too. Distinct from SMTP `MAIL_HOST`. |
   | `EMAILSQUIRE_WEBMAIL_URL` | Optional link in welcome mail and portal. Require an HTTPS webmail URL in production, or leave it empty to omit the link. Code accepts HTTP unchanged as permissive validation; that does not guarantee TLS or make HTTP suitable for production. |
   | `EMAILSQUIRE_SUPPORT_CONTACT` | Optional plain text, at most 200 characters, for portal Help if the domain has no manager. Empty shows generic help. |
   | `EMAILSQUIRE_SERVER_IPS` | Comma-separated public IPv4/IPv6 for PTR/SPF checks. Empty uses the mail host's A/AAAA records. Set actual public addresses behind NAT; remove the documentation IPs above. |
   | `EMAILSQUIRE_HIBP` | Default true; rejects compromised passwords using the Have I Been Pwned range API. No plaintext password is sent. Set false only as a deliberate policy for a server without that outbound access. |
   | `EMAILSQUIRE_REQUIRE_TWO_FACTOR` | `emailsquire.require_two_factor`: `admins` by default, or `everyone` for all console users. Confirmed TOTP or a usable passkey satisfies it. `off` is for tests; doctor fails it in production. No mailbox-owner 2FA is added. |
   | `EMAILSQUIRE_MANAGERS_CAN_SHARE` | Default false. True lets managers invite people and change domain access; server admins always can. |
   | `DOVEADM_URL`, `DOVEADM_API_KEY` | Loopback Dovecot HTTP endpoint for quota reads/session kicks and its shared random key. Match Dovecot's `doveadm_api_key`. |
   | `DOVECOT_AUTH_SOCKET` | Password-verification socket for portal sign-in/password changes. Default `/run/dovecot/emailsquire-auth`; app-owned `0600`. |

4. Set path overrides only when your host differs from the guide. These are the defaults in `config/emailsquire.php`:

   ```dotenv
   EMAILSQUIRE_POSTFIX_MAIN_CF=/etc/postfix/main.cf
   EMAILSQUIRE_MAIL_ROOT=/var/vmail
   EMAILSQUIRE_TRASH_DIR=/var/vmail/.emailsquire-trash
   EMAILSQUIRE_DKIM_DIR=/var/lib/rspamd/dkim
   EMAILSQUIRE_DKIM_SELECTOR_MAP=/etc/rspamd/emailsquire/dkim_selectors.map
   EMAILSQUIRE_RSPAMD_CONFIG_DIR=/etc/rspamd
   EMAILSQUIRE_FPM_PHP_INI=/etc/emailsquire/php.ini
   ```

   `main.cf` is read by doctor/catch-all detection. Mail root/trash identify the archive paths; DKIM paths identify keys/map; rspamd config is checked for unsafe write access; FPM ini is the file doctor inspects. Helpers do not inherit these values: update their options and writable paths too. `EMAILSQUIRE_POSTFIX_MAIN_CF` accepts a relative fixture path; use an absolute path on the server.

   Some settings have no environment key. In `config/emailsquire.php`, OS users are `emailsquire`, `vmail`, `_rspamd`; retention is 30 days; default quota is 5 GiB (`quota = 0` means that default, never unlimited); setup links last 72 hours; source-lock timeout is 5 seconds; DNS budget is 30 seconds. If you change retention, match `--retention-days` in the purge unit. `owner_reserved_locals` reserves postmaster, abuse, hostmaster, webmaster, admin, administrator, root, security, mailer-daemon, noc, ssladmin, ssl-admin and info against owner alias claims. Self-service switches are per domain in the console: forwarding defaults on, aliases and external forwarding off; per-mailbox override storage is not used.

   Console hashing uses Laravel's defaults (bcrypt, with `BCRYPT_ROUNDS=12` in the example). The backend plan describes Argon2id; to select it explicitly, use `HASH_DRIVER=argon2id`, `ARGON_MEMORY=65536`, `ARGON_THREADS=1`, `ARGON_TIME=3`. Laravel's unmodified Argon time default is 4. Mailbox hashes always use `{BLF-CRYPT}` with bcrypt cost 12 independently of console hashing. Keep `HASH_VERIFY` enabled; do not change an existing account's hash driver without checking compatibility.

   The example's Redis, Memcached, AWS and broadcast settings are unused by this database/local/SMTP deployment. Alternative provider credentials in `config/services.php` are not needed. `VITE_APP_NAME` is a build-time setting; `PHP_CLI_SERVER_WORKERS` is for the development server. Keep auth guards/providers and Fortify configuration at defaults; no separate passkey RP setting is needed.

## 5. Migrate and apply grants

Root must never run plain `php artisan` in a release: Laravel loads executable PHP caches owned by `emailsquire`, allowing a compromised service account to execute code as root. Use the shipped, root-owned `scripts/root-artisan` wrapper for every privileged command; it overrides all bootstrap caches and compiled views with a fresh root-only temporary directory, removed when the command exits.

1. Run migrations as the schema administrator before serving requests:

   ```sh
   cd /opt/emailsquire/current
   scripts/root-artisan config:clear
   scripts/root-artisan migrate --database=migrator --force
   scripts/root-artisan emailsquire:grants --database=migrator > /root/emailsquire-grants.sql
   chmod 0600 /root/emailsquire-grants.sql
   ```

   Run this block in root's shell. `--database=migrator` selects the named connection in `config/database.php`, using root's socket authentication and `DB_DATABASE` for the app schema. Grant generation uses that connection to read the table list and the configured app/guide schema names for its SQL. Runtime `DB_USERNAME` stays `emailsquire_app`. For a different runtime account/host, pass `--app-user=... --host=...` matching `.env`.

   Clear only the configuration cache before these commands so configuration is read afresh; cached configuration ignores command-scoped database overrides. This is before first service activation. On an existing installation, stop app requests and background work as in step 16 first, then rebuild runtime caches without administrator credentials before resuming service.

   If root socket authentication is unavailable, replace the migration and grant-generation commands above with this block in root's shell. Replace the administrator username and socket path. The password prompt does not echo; the shell variable exists only in the subshell, and each command receives credentials only for its own process:

   ```sh
   (
       set +x
       EMAILSQUIRE_MIGRATION_PASSWORD=$(systemd-ask-password 'MariaDB administrator password') || exit 1
       DB_MIGRATOR_SOCKET=/run/mysqld/mysqld.sock \
           DB_MIGRATOR_USERNAME=REPLACE_WITH_DB_ADMIN \
           DB_MIGRATOR_PASSWORD="$EMAILSQUIRE_MIGRATION_PASSWORD" \
           scripts/root-artisan migrate --database=migrator --force || exit 1
       umask 077
       DB_MIGRATOR_SOCKET=/run/mysqld/mysqld.sock \
           DB_MIGRATOR_USERNAME=REPLACE_WITH_DB_ADMIN \
           DB_MIGRATOR_PASSWORD="$EMAILSQUIRE_MIGRATION_PASSWORD" \
           scripts/root-artisan emailsquire:grants --database=migrator > /root/emailsquire-grants.sql
   )
   ```

   The shipped `migrator` connection has a Unix socket, database, username and password; it does not define TCP host/port settings. This alternative therefore still needs a local socket account with the required administrator rights. Use the same command-scoped procedure for later administrator operations, including IMA import. Do not export the credentials into the parent shell, write them into app configuration or run `config:cache` inside this subshell. Apply the reviewed grants through your root-controlled MariaDB administrator procedure too.

2. Review the SQL file. Replace `CHANGE-ME` with the same strong password in `DB_PASSWORD`, correctly SQL-escaped. Do not execute the placeholder or pipe unreviewed output into MariaDB. Confirm table/column scope, then apply:

   ```sh
   mariadb < /root/emailsquire-grants.sql
   mariadb <<'SQL'
   SHOW GRANTS FOR 'emailsquire_app'@'localhost';
   SHOW GRANTS FOR 'vmail'@'localhost';
   SHOW GRANTS FOR '_rspamd'@'localhost';
   SQL
   ```

   Correct existing accounts' authentication/passwords and revoke excess privileges separately. A schema-wide grant cannot be narrowed by a table-level revoke; replace broad grants with generated per-table grants. Protect and remove the SQL file through your normal secret-handling process after use.

3. Repair root-owned runtime files, then build caches as the app user:

   ```sh
   chown -R emailsquire:emailsquire /var/lib/emailsquire/storage /var/lib/emailsquire/cache
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan config:cache
   runuser -u emailsquire -- php artisan route:cache
   runuser -u emailsquire -- php artisan event:cache
   runuser -u emailsquire -- php artisan view:cache
   chmod -R go-rwx /var/lib/emailsquire/cache
   ```

   Rebuild config cache after changing `.env` and restart long-running PHP/queue processes. Run ordinary Artisan commands as `emailsquire`. Root is needed for socket-authenticated `migrator` operations, including IMA import below.

## 6. Configure Apache and PHP-FPM

1. Use a dedicated FPM master, separate from Roundcube's mod_php. Copy the packaged FPM ini for the app:

   ```sh
   cp /etc/php/8.4/fpm/php.ini /etc/emailsquire/php.ini
   chown root:emailsquire /etc/emailsquire/php.ini
   chmod 0640 /etc/emailsquire/php.ini
   ```

   Debian Apache workers run as `www-data`; check `/etc/apache2/envvars`. The socket below is `emailsquire:www-data 0660`. The FPM unit gives its master the supplementary group `www-data` so it can set that socket group. Do not add `www-data` to the `emailsquire` group or widen `.env` permissions. Do not enable Debian's global `php8.4-fpm` Apache configuration with `a2enconf`: the handler belongs only in this app's vhost, while Roundcube keeps mod_php.

2. Edit `/etc/emailsquire/php.ini` so these directives have the following effective values:

   ```ini
   disable_functions = exec,shell_exec,system,passthru,proc_open,popen,pcntl_exec
   expose_php = Off
   zend.exception_ignore_args = On
   allow_url_include = Off
   session.use_strict_mode = 1
   post_max_size = 64K
   opcache.validate_timestamps = 0
   ```

   This is FPM-only hardening. Do not disable CLI process functions globally: queue timeouts use CLI facilities. The plan also suggests `open_basedir`; its listed allowlist does not cover every server-check/config path. No complete tested allowlist is supplied, so if you enable it, validate app requests, Dovecot/MariaDB sockets and catch-all detection under FPM. Keep `EMAILSQUIRE_FPM_PHP_INI` pointing at the ini actually loaded. Doctor reads that file; it does not prove FPM loaded it.

3. Create `/etc/emailsquire/php-fpm.conf`:

   ```ini
   [global]
   daemonize = no
   error_log = /var/lib/emailsquire/storage/logs/fpm.log

   [emailsquire]
   user = emailsquire
   group = emailsquire
   listen = /run/php/emailsquire/fpm.sock
   listen.owner = emailsquire
   listen.group = www-data
   listen.mode = 0660
   pm = ondemand
   pm.max_children = 5
   pm.process_idle_timeout = 10s
   clear_env = yes
   catch_workers_output = yes
   security.limit_extensions = .php
   ```

   `pm.max_children` is an example capacity limit; size it for your memory/load. The master already runs as `emailsquire`, so FPM may report that pool user/group directives are ignored. It still runs as the service user.

4. Create `/etc/systemd/system/emailsquire-fpm.service`, using the hardening from the plan and shipped units:

   ```ini
   [Unit]
   Description=EmailSquire PHP-FPM
   After=mariadb.service

   [Service]
   Type=simple
   User=emailsquire
   Group=emailsquire
   SupplementaryGroups=www-data
   Environment=PHP_INI_SCAN_DIR=/etc/php/8.4/fpm/conf.d
   ExecStart=/usr/sbin/php-fpm8.4 --nodaemonize --fpm-config /etc/emailsquire/php-fpm.conf -c /etc/emailsquire/php.ini
   RuntimeDirectory=php/emailsquire
   RuntimeDirectoryMode=0755
   Restart=on-failure
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

   [Install]
   WantedBy=multi-user.target
   ```

   Do not add `MemoryDenyWriteExecute=yes` with PHP's default PCRE JIT; the units intentionally omit it. An optional alternative is to disable PCRE JIT with `pcre.jit=0` in the ini loaded by the affected PHP process, then enable `MemoryDenyWriteExecute=yes`. Validate startup, regular-expression use, queue work and app requests on the host before keeping this combination; CLI workers and FPM can load different ini files. This complete FPM config/unit is an operator example assembled from the plan, not a file under `deploy/systemd/`.

5. Enable the modules this Apache 2.4 configuration uses. `a2enmod proxy_fcgi` also enables its `proxy` dependency; `headers` covers static assets, `rewrite` handles redirects/routing and `ssl` terminates TLS. No `setenvif` directive is needed here:

   ```sh
   a2enmod proxy_fcgi headers rewrite ssl
   ```

   Obtain a certificate for the new name before adding certificate paths to the vhost. With DNS pointing here and port 80 reachable, use certbot's Apache authenticator in certificate-only mode:

   ```sh
   apache2ctl configtest
   certbot certonly --apache --cert-name admin.example.org -d admin.example.org
   certbot certificates
   ```

   `certonly --apache` uses temporary challenge configuration and leaves certificate installation to you; do not select, edit or replace Roundcube's vhost or expand its certificate. Use the certificate paths reported by certbot below. Keep the existing certbot renewal timer and check renewal with `certbot renew --dry-run` after installation. See [certbot's Apache plugin](https://eff-certbot.readthedocs.io/en/stable/using.html#apache).

6. Create `/etc/apache2/sites-available/emailsquire.conf`. Replace the hostname and certificate paths:

   ```apache
   <VirtualHost *:80>
       ServerName admin.example.org
       # Discard access records, including setup-token paths on redirects.
       CustomLog /dev/null combined
       ErrorLog ${APACHE_LOG_DIR}/emailsquire-error.log
       LogLevel warn
       RewriteEngine On
       # Let certbot's temporary Apache challenge alias handle renewal.
       RewriteCond %{REQUEST_URI} !^/\.well-known/acme-challenge/
       RewriteRule ^ https://admin.example.org%{REQUEST_URI} [R=301,L,NE]
   </VirtualHost>

   <VirtualHost *:443>
       ServerName admin.example.org
       DocumentRoot /opt/emailsquire/current/public
       SSLEngine on
       SSLCertificateFile /etc/letsencrypt/live/admin.example.org/fullchain.pem
       SSLCertificateKeyFile /etc/letsencrypt/live/admin.example.org/privkey.pem
       Include /etc/letsencrypt/options-ssl-apache.conf
       CustomLog /dev/null combined
       ErrorLog ${APACHE_LOG_DIR}/emailsquire-error.log
       LogLevel warn
       LimitRequestBody 65536
       ProxyRequests Off

       # Roundcube uses global mod_php; this vhost must use only FPM.
       php_admin_flag engine off

       RewriteEngine On
       # Reject path-info aliases, other PHP scripts and hidden path segments.
       RewriteRule ^/index\.php/ - [R=404,L]
       RewriteRule ^/(?!index\.php$).*\.php(?:/|$) - [R=404,L,NC]
       RewriteRule (^|/)\. - [R=404,L]

       <Directory /opt/emailsquire/current/public>
           Options FollowSymLinks
           AllowOverride None
           Require all granted
           DirectoryIndex index.php
           AcceptPathInfo Off

           # Directory rewrites run after authorization on the original URL.
           RewriteEngine On
           RewriteRule ^api/v1(?:/|$) index.php [END]
           RewriteCond %{REQUEST_FILENAME} -f
           RewriteRule ^ - [END]
           # Missing build assets are 404, never the SPA shell.
           RewriteRule ^build(?:/|$) - [R=404,END]
           RewriteCond %{REQUEST_FILENAME} -d
           RewriteRule ^ - [END]
           RewriteRule ^ index.php [END]
       </Directory>

       <FilesMatch "\.php$">
           SetHandler "proxy:unix:/run/php/emailsquire/fpm.sock|fcgi://localhost"
       </FilesMatch>

       <Location /build/>
           Header always set Strict-Transport-Security "max-age=31536000"
           Header always set X-Content-Type-Options "nosniff"
           Header always set Referrer-Policy "no-referrer"
       </Location>
   </VirtualHost>
   ```

   The HTTP vhost redirects to the fixed console hostname, except for certbot's temporary HTTP-01 challenge alias during issuance/renewal. The HTTPS web root is only `public/`. Existing static files, including Vite's `public/build/` assets, are served directly; `/api/v1` and other non-file requests go to `public/index.php`, preserving the original request URI and query string. Laravel serves the React shell for client routes such as `/setup/...`, `/invite` and `/my/...`, JSON under `/api/v1` and health at `/up`. Do not rewrite `/api/v1` to static `index.html`, strip its prefix or expose the app directory as the web root. All routing lives in the vhost's `<Directory>` block; `AllowOverride None` ignores the shipped `.htaccess`. `Options FollowSymLinks` permits the root-controlled `current` release symlink and disables directory indexes and MultiViews.

   [`php_admin_flag engine off`](https://www.php.net/manual/en/configuration.changes.php) is the mod_php boolean directive; [`engine`](https://www.php.net/manual/en/apache.configuration.php) controls only Apache's PHP module, not FPM. Keep it in this vhost, with the explicit `proxy_fcgi` handler above. Do not disable mod_php globally or switch Apache's MPM: Roundcube still needs its current configuration. Only `index.php` is reachable as PHP; `/index.php/...` aliases and other PHP URLs are rejected. An unavailable FPM socket must produce a gateway failure, never execute PHP through mod_php or serve its source.

   Setup tokens are URL-path credentials. Apache has no `CustomLog off` directive: `CustomLog /dev/null combined` in both vhosts discards access records and overrides inherited `CustomLog`, including on HTTP redirects. It does not establish token-free logs: Apache error messages can contain request paths. Keep `/var/log/apache2/emailsquire-error.log` and its rotated copies root-controlled, accessible only to root and the designated log-reading group (for example `root:adm 0640`), and preserve those permissions in log rotation. Avoid debug/trace logging. Restrict FPM and application logs too. Redact `/setup/<token>` paths before exporting logs to monitoring, support tickets or other readers; check all effective Apache includes and proxy logs for re-enabled request logging. A global [`GlobalLog`](https://httpd.apache.org/docs/2.4/mod/mod_log_config.html#globallog) still applies even with a vhost `CustomLog`; exclude this hostname there if one is configured. Validate the policy with a disposable setup link before issuing a real one.

   Optionally restrict console API access to office or VPN addresses while keeping mailbox-owner sign-in and the portal public. Add these sections inside the HTTPS vhost, replacing the example networks and keeping this order:

   ```apache
   <Location /api/v1>
       AuthMerging Off
       Require ip 192.0.2.0/24 2001:db8::/64
   </Location>
   <Location /api/v1/auth/>
       AuthMerging Off
       Require all granted
   </Location>
   <Location /api/v1/portal/>
       AuthMerging Off
       Require all granted
   </Location>
   ```

   Apache merges matching [`<Location>` sections in file order](https://httpd.apache.org/docs/2.4/sections.html#merging), not by longest prefix. Put the broad restriction first and the longer-prefix exemptions after it; [`AuthMerging Off`](https://httpd.apache.org/docs/2.4/mod/mod_authz_core.html#authmerging) makes each later authorization rule replace the earlier one. Both console users and mailbox owners use authentication endpoints. The `<Directory>` rewrites run after authorization, so the original `/api/v1` URL is checked before the front-controller fallback. Keep that routing out of server-level rewrite rules, which run before authorization. Reject `/index.php/...` aliases so they cannot reach a console route outside the restricted prefix. Keep `/my/*`, the SPA shell and its assets reachable for the portal. Do not add a blanket vhost IP deny or later authorization sections that override these rules. Verify console denial from outside the allowlist, rejection of `/index.php/api/v1/me`, and portal sign-in from both sides; Apache's denial is outside the app's problem-JSON handler.

   `SecurityHeaders` middleware supplies a per-response Vite nonce in CSP, HSTS on secure requests, `nosniff`, `Referrer-Policy: no-referrer` and Permissions-Policy. Its CSP permits same-origin scripts/styles with the nonce, same-origin connections/fonts, `data:` images, no frames, no base URI and same-origin form actions. Do not add a second static CSP to PHP responses: it would not carry their nonce. The `/build/` headers cover Apache-served assets. Keep API responses uncached so session, CSRF and `Cache-Control: no-store` behavior survives.

   This example terminates HTTPS directly in Apache. `proxy_fcgi` passes the connection's real client address as `REMOTE_ADDR` and the TLS state as `HTTPS` to FPM; preserve these values instead of trusting client-supplied forwarding headers. There is no configured trusted-proxy chain in the app. A separate reverse proxy needs reviewed client-IP/HTTPS configuration before fail2ban, Dovecot penalties and same-origin checks can be trusted.

   If you terminate TLS with nginx instead (not the ISPmail default), the same public-root, FPM-only routing, static-asset, token-log, allowlist and security-header rules apply. Review client-IP/HTTPS forwarding and Apache's bindings before changing the TLS entry point; both servers cannot bind the same address/port.

7. Validate and start the services:

   ```sh
   runuser -u emailsquire -- /usr/sbin/php-fpm8.4 -t \
       --fpm-config /etc/emailsquire/php-fpm.conf -c /etc/emailsquire/php.ini
   systemctl daemon-reload
   systemctl enable --now emailsquire-fpm.service
   ls -l /run/php/emailsquire/fpm.sock
   runuser -u www-data -- test -w /run/php/emailsquire/fpm.sock
   runuser -u www-data -- test ! -r /etc/emailsquire/.env
   a2ensite emailsquire.conf
   apache2ctl configtest
   systemctl reload apache2
   curl -I https://admin.example.org/
   curl -i https://admin.example.org/api/v1/me
   ```

   Confirm the socket is `emailsquire:www-data 0660`, Apache can connect and `www-data` cannot read `.env`. Inspect HTTPS response headers and the FPM/application logs to confirm requests reach the app's FPM master. Check that Roundcube still works. A guest `/api/v1/me` request should return 401 problem JSON from an allowed address if you enabled console allowlisting; Apache returns 403 outside it. Check a real `/build/assets/...` URL from the release and that a missing `/build/...` asset returns 404. The SPA uses same-origin session cookies and obtains CSRF at `/api/v1/auth/csrf`; changing requests need that token. Use the browser for setup/sign-in, not a tokenless API POST.

## 7. Install the queue worker and scheduler

1. Copy the shipped units:

   ```sh
   cd /opt/emailsquire/current
   install -m 0644 deploy/systemd/emailsquire-queue.service /etc/systemd/system/
   install -m 0644 deploy/systemd/emailsquire-schedule.service /etc/systemd/system/
   install -m 0644 deploy/systemd/emailsquire-schedule.timer /etc/systemd/system/
   systemctl daemon-reload
   ```

   Leave the worker and scheduler stopped until the preflight checkpoint in step 10.

2. Check unit definitions now; check timers and logs after activation in step 10:

   ```sh
   systemctl cat emailsquire-queue.service emailsquire-schedule.service
   systemctl list-timers 'emailsquire-*'
   journalctl -u emailsquire-queue.service -u emailsquire-schedule.service
   ```

   The worker runs as `emailsquire`:

   ```text
   /usr/bin/php /opt/emailsquire/current/artisan queue:work --sleep=3 --max-time=3600
   ```

   It restarts automatically, can write only persistent storage, and has Unix/IPv4/IPv6 access for MariaDB, SMTP and DNS. It sends invitations, reset links, welcome mail and security notices, and runs DNS jobs. An empty queue does not prove it is alive.

   The scheduler runs as `emailsquire` every minute:

   ```text
   /usr/bin/php /opt/emailsquire/current/artisan schedule:run
   ```

   It refreshes usage every 15 minutes and queues DNS checks every six hours. `emailsquire:dns-checks` is the scheduled callback's name, not an Artisan command. Do not add a second cron scheduler. Restart the worker after every release; it keeps loaded code in memory.

## 8. Install the maildir and DKIM helpers

1. Keep the mail root owned by `vmail` and confirm the mail filesystem is mounted. Create trash without recursively changing existing mailbox ownership:

   ```sh
   install -d -o vmail -g vmail -m 0700 /var/vmail/.emailsquire-trash
   ```

   Trash must be on the same filesystem as mail root: archiving uses `rename()`. Keep the default inside `/var/vmail`. Under systemd, use one `ReadWritePaths=/var/vmail`; adding trash as a second entry creates a separate bind mount and can cause `EXDEV` on the same disk. For trash elsewhere, both maildir services need one common parent as their writable path.

2. Copy the maildir units; leave their timers stopped until the preflight checkpoint in step 10:

   ```sh
   cd /opt/emailsquire/current
   install -m 0644 deploy/systemd/emailsquire-maildir.service /etc/systemd/system/
   install -m 0644 deploy/systemd/emailsquire-maildir.timer /etc/systemd/system/
   install -m 0644 deploy/systemd/emailsquire-maildir-purge.service /etc/systemd/system/
   install -m 0644 deploy/systemd/emailsquire-maildir-purge.timer /etc/systemd/system/
   systemctl daemon-reload
   ```

   Both services use `User=vmail`, `Group=vmail`, `UMask=0077` and Unix sockets only. Their commands are:

   ```text
   /usr/bin/php /opt/emailsquire/current/helpers/maildir.php run
   /usr/bin/php /opt/emailsquire/current/helpers/maildir.php purge
   ```

   `run` is due one minute after boot and every minute after activation; accuracy is one second. Purge runs daily and catches up after downtime. It removes archives older than 30 days using the UTC stamp in their name. Run/purge share `/var/vmail/.emailsquire-maildir.lock` and never overlap.

3. If paths, schema names or socket differ, use systemd overrides with the required options. For example, a manual run uses the following command; run it only after the preflight checkpoint in step 10:

   ```sh
   runuser -u vmail -- php /opt/emailsquire/current/helpers/maildir.php run \
       --mail-root=/var/vmail --trash-dir=/var/vmail/.emailsquire-trash \
       --guide-schema=mailserver --app-schema=emailsquire \
       --socket=/run/mysqld/mysqld.sock --retention-days=30
   ```

   `purge` takes the same options. Retention must be 1 to 99999 whole days; schema names allow only letters, digits and underscores. In a systemd drop-in, clear the old command with empty `ExecStart=`, then provide its replacement. Match `ReadWritePaths`. Helpers never read `.env`, including database or retention settings. There is no dry-run; the example processes tasks.

4. Prepare DKIM paths and copy its units, preserving the guide's existing keys:

   ```sh
   install -d -o _rspamd -g _rspamd -m 0750 /var/lib/rspamd/dkim
   install -d -o root -g _rspamd -m 0770 /etc/rspamd/emailsquire
   cd /opt/emailsquire/current
   install -m 0644 deploy/systemd/emailsquire-dkim.service /etc/systemd/system/
   install -m 0644 deploy/systemd/emailsquire-dkim.timer /etc/systemd/system/
   ```

   The key directory's `0750` mode above is a conservative choice for this example; the sources require its owner to be `_rspamd` but do not mandate a directory mode. Enable its timer only after the preflight checkpoint in step 10. Its command is:

   ```text
   /usr/bin/php /opt/emailsquire/current/helpers/dkim.php run
   ```

   It runs every minute as `_rspamd:_rspamd`, with `UMask=0077`, Unix sockets only and `ReadWritePaths=/var/lib/rspamd/dkim /etc/rspamd/emailsquire`. Its full option syntax is below; run it only after the preflight checkpoint in step 10:

   ```sh
   runuser -u _rspamd -- php /opt/emailsquire/current/helpers/dkim.php run \
       --dkim-dir=/var/lib/rspamd/dkim \
       --selector-map=/etc/rspamd/emailsquire/dkim_selectors.map \
       --guide-schema=mailserver --app-schema=emailsquire \
       --socket=/run/mysqld/mysqld.sock
   ```

   It supports only `run`; no purge or retention option. Match overrides with app paths and writable directories. PHP OpenSSL generates RSA 2048 keys written `0600`; `.emailsquire-dkim.lock` is in the key directory.

   Run helpers only as their directory's owner, never root or `emailsquire`. Both refuse any other effective user, including root. They reject unsafe paths/symlinks and recheck guide rows before acting. I/O failures retry with backoff and fail after five attempts; unsafe tasks fail immediately. Inspect logs/task errors even on exit code 0, since retrying work can return success. Exit code 1 means failed/refused work or a stopped run; 2 means usage/connection failure.

## 9. Add the Dovecot auth socket and doveadm API

1. Create `/etc/dovecot/conf.d/99-emailsquire.conf`, matching the Trixie test host. Replace the key with a random value also set in `DOVEADM_API_KEY`:

   ```text
   doveadm_api_key = REPLACE_WITH_RANDOM_DOVEADM_KEY
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

   The command allowlist limits the shared key to quota reads, session kicks and user lookups, refusing mail-reading commands such as `fetch`. Keep `"quota get"` quoted and space-separated from `kick user`; a comma-separated list blocks quota reads.

   The relative listener creates `/run/dovecot/emailsquire-auth` on the guide layout. Preserve existing Postfix auth/LMTP listeners. If you already configure doveadm or its API key, reconcile definitions instead of adding conflicting ones.

2. Protect the config, validate Dovecot and reload:

   ```sh
   chown root:root /etc/dovecot/conf.d/99-emailsquire.conf
   chmod 0600 /etc/dovecot/conf.d/99-emailsquire.conf
   doveconf -n >/dev/null
   doveadm reload
   stat -c '%U %a %n' /run/dovecot/emailsquire-auth
   ```

   Expect `emailsquire 600`. Do not widen the socket mode. Portal password checks use Dovecot's auth-client protocol with the real client IP so penalties/logging remain per client. HTTP `authTest` is unavailable; the API serves usage/session kicks only. Keep 8080 on loopback. Unreachable doveadm makes usage unavailable; unreachable auth refuses portal sign-in.

## 10. Point rspamd at the DKIM selector map

1. Copy existing selector-map contents to the helper's directory, keeping the original as backup. Copy only once; do not overwrite an EmailSquire-managed map:

   ```sh
   if [ -f /etc/rspamd/dkim_selectors.map ] && \
      [ ! -e /etc/rspamd/emailsquire/dkim_selectors.map ]; then
       install -o _rspamd -g _rspamd -m 0644 \
           /etc/rspamd/dkim_selectors.map /etc/rspamd/emailsquire/dkim_selectors.map
   fi
   ```

   The regular map file contains lines such as `example.org dkim`. The helper replaces it with a temporary file/rename in the same directory, so directory write access is required. `/etc/rspamd/emailsquire` must be `root:_rspamd 0770`. `/etc/rspamd`, `local.d`, `override.d` and their config files remain root-controlled and unwritable by `_rspamd`.

2. Edit `/etc/rspamd/local.d/dkim_signing.conf` as in the test host:

   ```sh
   sed -i 's|^selector_map = .*|selector_map = "/etc/rspamd/emailsquire/dkim_selectors.map";|' \
       /etc/rspamd/local.d/dkim_signing.conf
   ```

   If no matching line existed, add it by hand. Keep the guide's path and other settings:

   ```text
   path = "/var/lib/rspamd/dkim/$domain.$selector.key";
   selector_map = "/etc/rspamd/emailsquire/dkim_selectors.map";
   allow_username_mismatch = true;
   ```

   Inspect `override.d/dkim_signing.conf` too: it wins over `local.d`. Confirm the effective selector map points here. A missing map is acceptable if no old map needs preserving; first activation creates it. An existing `<domain>.dkim.key` can remain rspamd's fallback selector `dkim` with no map line for the domain.

3. Reload rspamd after checking its configuration:

   ```sh
   systemctl reload rspamd
   ```

4. Run doctor as a preflight checkpoint now that application and mail-service configuration is complete, before creating the first administrator, using the console or running helpers:

   ```sh
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan emailsquire:doctor --require-server
   runuser -u emailsquire -- php artisan emailsquire:doctor --require-server --json
   ```

   Resolve findings using step 13 and rerun before proceeding. In particular, check guide schema/data and unsafe rspamd configuration permissions. The default root-only sender-map check is the documented exception: `postfix.sender_login_maps` skips under the app user because maps are `root:postfix 0640`, and `--require-server` reports that as a failure. Preserve those permissions and inspect that check as root:

   ```sh
   scripts/root-artisan emailsquire:doctor --require-server
   chown -R emailsquire:emailsquire /var/lib/emailsquire/storage
   ```

   Keep the app-user results for every other access check. Review warnings and skips, explicitly verify `fs.dkim_dir` and `fs.dkim_selector_map`, and resolve all other failures before activation; a root-only pass does not prove app access.

5. After preflight, activate the worker, scheduler and helper timers:

   ```sh
   systemctl daemon-reload
   systemctl enable --now emailsquire-queue.service emailsquire-schedule.timer \
       emailsquire-maildir.timer emailsquire-maildir-purge.timer emailsquire-dkim.timer
   systemctl list-timers 'emailsquire-*'
   ```

   After administrator setup in step 12, use a domain's DNS tab to “Read keys from the server” and inspect/import existing keys. Generate, publish the TXT record, check DNS, then activate once the latest check shows that exact key. A server admin can force activation, which is audited. Wait at least 15 minutes after activation before retiring an old key. Legacy selectors are displayed but not activated/retired by the `esYYYYMMDD` workflow. Later helper activations need no reload; rspamd rereads the renamed map.

   Domain deletion leaves its keys and map line on disk. Review/remove them manually when no longer needed; they are not purged automatically.

## 11. Configure fail2ban

1. Install the filter and jail:

   ```sh
   cd /opt/emailsquire/current
   install -m 0644 deploy/fail2ban/filter.d/emailsquire.conf /etc/fail2ban/filter.d/
   install -m 0644 deploy/fail2ban/jail.d/emailsquire.conf /etc/fail2ban/jail.d/
   ```

   The jail watches `/var/lib/emailsquire/storage/logs/laravel.log`, uses `backend=auto` and counts 10 failures in 10 minutes. Console, mailbox and passkey failures match, as do older lines without `kind=`. Ban duration, action and port selection come from your fail2ban defaults; the shipped jail does not specify them. Review them for Apache's HTTP/HTTPS ports. This jail reads application authentication logs, not Apache access logs; keep the vhost's access logging disabled.

   Authentication failures use this warning-line format:

   ```text
   [2026-10-10 12:00:00] production.WARNING: emailsquire auth failure kind=console user=a@b.c ip=203.0.113.9
   ```

   `kind` is `console`, `mailbox` or `passkey`. `user` is the supplied address, sanitised to printable, space-free ASCII and truncated to 100 characters; `user=-` means no address is known, such as a passwordless passkey attempt with an unknown credential. `ip` is the client address seen by the app. Passwords are never included. Use this format when troubleshooting failures and verifying the filter against the actual log.

2. Validate and reload:

   ```sh
   fail2ban-client -t
   systemctl enable --now fail2ban
   fail2ban-client reload
   fail2ban-client status emailsquire
   ```

   Match the jail if you change log channel/path. Console typos on hosted domains also reach Dovecot, so its jail can count the same attempts. Offices behind NAT share penalties, the portal's 30-per-minute IP cap and jail counts. Adjust your local jail policy or `ignoreip` as appropriate. Do not trust client-IP headers from an unreviewed proxy.

## 12. Create the first server admin

1. Create a console account and print its setup link:

   ```sh
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan emailsquire:create-admin you@example.org --name="Your Name"
   ```

   There is no default password. The command prints a single-use `/setup/<token>?email=...` link expiring in 72 hours; it does not email it. Open it privately over HTTPS and set your password. For an existing account without a password it reissues a link; with a password it requires `--reset`. It can promote an existing console user to server admin.

2. On My profile, confirm TOTP or enrol a passkey before using the console. Save recovery codes securely. A usable passkey with required device verification satisfies 2FA, but passwordless sign-in requires a discoverable passkey. Registration only prefers discoverable credentials; other accepted passkeys still satisfy required 2FA after password sign-in and can be used for step-up, where the app supplies their credential IDs. Required 2FA blocks ordinary console routes until a second factor exists.

3. Check delivery through the queue before relying on emailed invitations/resets. Welcome mail contains client settings, never a mailbox password. Give new mailbox passwords to owners through your own secure channel.

## 13. Run doctor and resolve its findings

1. Use these checks for the preflight in step 10 and repeat after changes or upgrades. Run as the app user so access checks reflect the application:

   ```sh
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan emailsquire:doctor --require-server
   runuser -u emailsquire -- php artisan emailsquire:doctor --require-server --json
   ```

   Doctor returns exit code 1 for failed checks, including skipped server checks converted by `--require-server`. Warnings do not make it exit nonzero. Read each row and its items. It does not repair guide data; it also clears the catch-all capability cache while checking it.

2. Resolve findings using the JSON check ID:

   | Check ID | What you should do for fail, warn or skip |
   | --- | --- |
   | `database.connection` | Check MariaDB, socket/TCP settings, account host/password. Replace an applied `CHANGE-ME` password with `ALTER USER` and matching `DB_PASSWORD`; rebuild config cache. |
   | `guide.schema` | Confirm guide schema, table visibility and reported column types/lengths against Trixie. Do not migrate the guide schema with Laravel. Other guide checks skip until resolved. |
   | `guide.domain_unique_key` | Back up and merge duplicate domains and dependent rows deliberately; then add doctor's unique index (`ALTER TABLE mailserver.virtual_domains ADD UNIQUE KEY (name);`). A missing index without duplicates is a warning after some Bookworm restores. |
   | `guide.password_schemes` | Reset weak/unrecognised hashes. Legacy SHA-crypt is a warning, remains usable and upgrades on password change. Never copy hashes into logs/tickets. |
   | `guide.address_format` | Review uppercase/non-ASCII rows. Coordinate normalisation with aliases and actual Dovecot homes; DB spelling changes do not move mail. |
   | `guide.domain_mismatch` | Back up and correct wrong/missing `domain_id` references after verifying the intended domain. |
   | `guide.alias_shadows_mailbox` | Decide whether forwarding without a copy is intended. Enable keep-a-copy (`source → source`) if you want local mail too; do not remove deliberate forwarding automatically. |
   | `guide.duplicate_aliases` | Review/remove duplicate source/destination pairs while preserving intended targets. |
   | `app.migrated` | Run administrator migrations on `migrator`, regenerate/apply grants. The app needs read-only access to `migrations`. |
   | `app.orphans` | Review listed app references to domains/mailboxes changed outside EmailSquire. Correct/remove stale references under an administrator-controlled maintenance procedure. Usage refresh prunes stale usage snapshots. Check remaining DKIM files. No general repair command is supplied. |
   | `helper.maildir_queue` | Check timer, journal, socket grants, mounts, space and permissions. Inspect with `emailsquire:maildir-tasks`; fix the cause before retrying failed IDs. It flags failed tasks and pending tasks overdue by 10 minutes, but not every stuck running task. |
   | `postfix.alias_maps` | Make `main.cf` readable to the app and include an email-to-itself map in `virtual_alias_maps`. The guide uses `mysql:/etc/postfix/mariadb-email2email.cf` beside the alias map. Preserve extra maps, reload Postfix and rerun doctor. Catch-all remains unavailable until OK. |
   | `postfix.sender_login_maps` | Inspect active maps as root; replace `MAILSERVER-PASSWORD-HERE` with the guide map account's actual password. Empty maps warn that authenticated users can send as any address. A map querying aliases grants send-as; the installer normally ends with own-address-only email2email. Keep maps `root:postfix 0640`. |
   | `dovecot.doveadm_api` | Match API keys, check loopback listener/endpoint and reload Dovecot. The user lookup must return exit code 67 before doctor probes `fetch` using only `guid` and a random 32-hex mailbox search; `fetch` must answer `unAuthorized`. If the probe user exists, doctor fails without fetching: correct the static/legacy userdb mapping for `doctor-probe@invalid`. Transport, HTTP-auth and invalid-request failures retain their own diagnostics. Set `doveadm_allowed_commands = "quota get" kick user` if an answered probe permits the command. |
   | `dovecot.auth_socket` | Reload listener config; verify socket path, owner `emailsquire`, mode `0600` and directory traversal. Do not widen socket mode. |
   | `fs.mail_root` | Check mount, configured root and owner `vmail`. Put trash on the same filesystem with one common writable bind path. Do not blindly chown the entire mail tree. |
   | `fs.dkim_dir` | Create/check the key directory and owner `_rspamd`. It can skip even with `--require-server`; the helper still needs it. |
   | `fs.dkim_selector_map` | Create the dedicated writable map directory, reject symlinks/non-files and point effective rspamd config at the map. Remove `_rspamd` write access to config directories/files. Verify unreadable config yourself as root; the checker cannot confirm unseen settings. |
   | `runtime.debug` | Set production/false, rebuild caches and restart FPM/worker. Debug-on in production refuses web requests. |
   | `runtime.caches` | Build active release config/route caches as `emailsquire`. Do not ship a build-machine config cache. |
   | `runtime.fpm_ini` | Fix ini path/readability, all six process-function disables and `zend.exception_ignore_args=On`. Confirm FPM actually loads it and restart. |
   | `mail.queue_worker` | Start/restart worker; inspect logs/failed jobs. This checks overdue `jobs`, not systemd liveness or configured driver; a quiet queue can pass with no worker. |
   | `mail.smtp` | Check local Postfix and SMTP host/port for a 220 greeting. It checks SMTP config even if `MAIL_MAILER` changes; this does not prove delivery. |
   | `mail.host` | Set a reachable full hostname on the mail TLS certificate, not a bare name or IP. |
   | `mail.webmail_url` | Use an HTTPS webmail URL in production, or empty. The check accepts HTTP too and does not establish TLS safety; invalid values are omitted from welcome mail and portal. |
   | `auth.require_two_factor` | Set `admins` or `everyone` and rebuild config. Treat unexpected values as mistakes although code falls back to admins. |
   | `runtime.test_environment` | Use production on the real server, rebuild caches/restart. `e2e`/`testing` are for isolated copies. |

3. The default guide layout has one expected limitation: `postfix.sender_login_maps` skips as the app user because credential maps are `root:postfix 0640`. With `--require-server` this becomes a failure. Keep those permissions; rerun as root to inspect that check:

   ```sh
   cd /opt/emailsquire/current
   scripts/root-artisan emailsquire:doctor --require-server
   chown -R emailsquire:emailsquire /var/lib/emailsquire/storage
   ```

   Retain the app-user results for other access checks; root alone does not prove app access. `fs.dkim_dir` and `fs.dkim_selector_map` are not classified as server checks in current code, so `--require-server` does not turn their skips into failures. Check them explicitly. Doctor does not test TLS origin, actual delivery, every helper grant or systemd sandboxes.

## 14. Import from ISPmail Admin

1. Back up and stop old ISPmail Admin writes before switching operators. Domains, mailboxes and aliases already use the same tables; no migration is needed for them. Run doctor first and resolve anomalous rows.

2. Import blocked addresses, using the administrator connection to read the old table. The commands below use root's passwordless socket authentication. If you need an administrator password, first stop app requests/background work and clear configuration cache as in step 5, then apply its command-scoped `DB_MIGRATOR_*` variables to each import command. Rebuild runtime caches without administrator credentials before resuming service:

   ```sh
   cd /opt/emailsquire/current
   scripts/root-artisan emailsquire:import-ima --database=migrator
   chown -R emailsquire:emailsquire /var/lib/emailsquire/storage
   ```

   It copies `mailserver.blacklist_email` into `emailsquire.blocked_addresses`, decodes HTML entities once, trims values and limits reasons to 255 characters. Already-blocked addresses are skipped; invalid ones are listed as refused. The app account cannot normally see `blacklist_email`, so default-connection “No table visible” is not proof it is absent. Use `migrator`.

3. Review counts and resolve refused entries. After confirming import/backup, optionally drop the old table:

   ```sh
   scripts/root-artisan emailsquire:import-ima --database=migrator --drop
   chown -R emailsquire:emailsquire /var/lib/emailsquire/storage
   ```

   `--drop` deletes only `blacklist_email` and refuses if any row was refused. There is no dry-run or guard against IMA writes between read/drop. Keep IMA unavailable for writes throughout. The command does not import console users or old permissions.

## 15. Optional receiving/send-only schema extension

1. Apply this only for “Stop receiving mail” and “Send-only” controls. The app detects the columns; it never adds them. Without them, other features work and API switch writes return `schema_extension_missing`. Back up both schemas and affected Postfix/Dovecot config. As the MariaDB administrator:

   ```sql
   ALTER TABLE mailserver.virtual_users
     ADD COLUMN enabled TINYINT(1) NOT NULL DEFAULT 1,
     ADD COLUMN sendonly TINYINT(1) NOT NULL DEFAULT 0;
   ```

   Existing mailboxes keep receiving and stay normal mailboxes. If a column exists, inspect its definition and add only the missing one. Existing grants cover new columns. Detection is cached for 10 minutes; wait for expiry, with no service restart needed. `SchemaExtension::flush()` clears only its two entries internally, but there is no dedicated Artisan command for it. Never run `cache:clear` on a running server: it removes used-TOTP markers, single-use passkey challenge markers and rate limits along with capabilities.

2. In `/etc/postfix/mariadb-virtual-mailbox-maps.cf`, preserve credentials/connection settings and change only the query:

   ```text
   query = SELECT 1 FROM virtual_users WHERE email='%s' AND enabled = 1 AND sendonly = 0
   ```

3. Leave `/etc/postfix/mariadb-email2email.cf` untouched. It serves aliases and sender-login ownership; gating it prevents send-only mailboxes sending as themselves. Make a receiving-only copy:

   ```sh
   install -o root -g postfix -m 0640 /etc/postfix/mariadb-email2email.cf \
       /etc/postfix/mariadb-email2email-receiving.cf
   ```

   Change only the copy's query:

   ```text
   query = SELECT email FROM virtual_users WHERE email='%s' AND enabled = 1 AND sendonly = 0
   ```

   For the installer's two-map layout:

   ```sh
   postconf -e 'virtual_alias_maps = mysql:/etc/postfix/mariadb-virtual-alias-maps.cf, mysql:/etc/postfix/mariadb-email2email-receiving.cf'
   systemctl reload postfix
   ```

   If you have extra maps, replace only the email2email entry. Keep `email2email` in the copy's filename for catch-all checks when unreadable by the app. `smtpd_sender_login_maps` keeps the original ungated map. Postfix rejects direct recipients with receiving off or sendonly on at RCPT with `550 5.1.1 ... User unknown in virtual mailbox table`, unless an alias/catch-all accepts them.

4. Gate Dovecot LMTP too so aliases/keep-a-copy cannot bypass the restriction. Find the existing `userdb sql` block; the test host calls its file `/etc/dovecot/conf.d/99-ispmail-sql.conf`, but your installer's filename may differ. Preserve your quota expression and other query settings; append only the protocol condition:

   ```text
   userdb sql {
     query = SELECT email as user, \
       IF(quota > 0, CONCAT(quota, 'B'), NULL) AS quota_storage_size \
       FROM virtual_users WHERE email='%{user}' \
       AND (enabled = 1 AND sendonly = 0 OR '%{protocol}' <> 'lmtp')
     iterate_query = SELECT email as user FROM virtual_users
   }
   ```

   Leave `passdb` and `iterate_query` unchanged. Validate/reload:

   ```sh
   doveconf -n >/dev/null
   doveadm reload
   ```

   `%{protocol}` is the Dovecot 2.4 variable used in mail-truth tests. The condition stops LMTP but preserves IMAP/submission. Until both server query changes are applied, switches only write columns and do not enforce intended delivery behavior.

5. Test with a disposable mailbox on your domain. Receiving-off/send-only mailboxes still read old mail and send as themselves. Direct mail is rejected at RCPT; accepted aliases to the mailbox bounce at LMTP with `550 5.1.1 ... User doesn't exist`. Forward-only rules from the address still forward; with keep-a-copy other targets receive and the local copy bounces. Catch-all expansion can still accept an otherwise-unknown address and follows its targets. Ordinary “Disable mailbox” instead prevents authentication while mail keeps arriving.

   To remove the extension, undo Postfix/Dovecot query changes first, then drop columns as administrator; otherwise queries fail. Wait up to 10 minutes for capability cache expiry; no restart is needed. Do not run `cache:clear` on a running server.

## 16. Upgrade EmailSquire

Existing installations must add `doveadm_allowed_commands = "quota get" kick user` to `/etc/dovecot/conf.d/99-emailsquire.conf` and run `doveadm reload` before the post-upgrade doctor check. This confines the shared key to the three commands EmailSquire uses.

1. Read migration/operations notes, including `docs/superpowers/plans/*-followups.md` in the source checkout. Back up first. Build/verify a fresh artifact and extract to a new root-owned release directory as in step 3. Link existing `.env` and storage without copying over either. Move the new bootstrap cache into a separate protected `/var/lib/emailsquire/cache/<release>` directory; do not reuse old caches.

   After extraction and setting code ownership/modes, prepare only the new release's links:

   ```sh
   EMAILSQUIRE_VERSION=REPLACE_WITH_NEW_RELEASE_ID
   EMAILSQUIRE_RELEASE=/opt/emailsquire/releases/$EMAILSQUIRE_VERSION
   mv "$EMAILSQUIRE_RELEASE/storage" "$EMAILSQUIRE_RELEASE/storage.dist"
   ln -s /var/lib/emailsquire/storage "$EMAILSQUIRE_RELEASE/storage"
   mv "$EMAILSQUIRE_RELEASE/bootstrap/cache" "/var/lib/emailsquire/cache/$EMAILSQUIRE_VERSION"
   ln -s "/var/lib/emailsquire/cache/$EMAILSQUIRE_VERSION" "$EMAILSQUIRE_RELEASE/bootstrap/cache"
   ln -s /etc/emailsquire/.env "$EMAILSQUIRE_RELEASE/.env"
   chown -R emailsquire:emailsquire "/var/lib/emailsquire/cache/$EMAILSQUIRE_VERSION"
   chmod -R go-rwx "/var/lib/emailsquire/cache/$EMAILSQUIRE_VERSION"
   ```

2. Stop requests and background work before migrations. On the old release:

   ```sh
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan down
   systemctl stop emailsquire-fpm.service emailsquire-queue.service \
       emailsquire-schedule.timer emailsquire-schedule.service \
       emailsquire-maildir.timer emailsquire-maildir.service \
       emailsquire-maildir-purge.timer emailsquire-maildir-purge.service \
       emailsquire-dkim.timer emailsquire-dkim.service
   ```

   Postfix/Dovecot keep serving mail. Coordinate stopping helpers so you do not interrupt filesystem work unnecessarily. Their next run recovers running tasks; inspect errors after interruption.

3. From the new release, migrate before activation:

   ```sh
   cd /opt/emailsquire/releases/REPLACE_WITH_NEW_RELEASE_ID
   scripts/root-artisan config:clear
   scripts/root-artisan migrate --database=migrator --force
   scripts/root-artisan emailsquire:grants --database=migrator > /root/emailsquire-grants.sql
   chmod 0600 /root/emailsquire-grants.sql
   ```

   Use root's socket authentication, or the command-scoped administrator procedure in step 5 after clearing configuration cache. Keep privileged credentials out of `.env` and the rebuilt runtime cache. Review/apply grants as in step 5, especially after new tables. They are additive. Upgrading from helpers with table-wide `UPDATE` requires revoking that old grant first as MariaDB root:

   ```sql
   REVOKE UPDATE ON emailsquire.helper_tasks FROM 'vmail'@'localhost';
   REVOKE UPDATE ON emailsquire.helper_tasks FROM '_rspamd'@'localhost';
   ```

   Revoke only if the account/grant exists; “There is no such grant” means nothing was present. Then apply generated column grants. Verify `SHOW GRANTS` has `UPDATE (...)` only and no broader schema/global privilege overriding it.

   Older transitions: phase 2b added `admin_setup_tokens`; 2c added invitation fields; 2d added an audit index; phase 3 added `sessions.mailbox_address` and **must migrate before new code serves requests**, because every session writes it; phase 4 added DKIM/DNS tables and task result fields; passkeys added credential storage. Current migrations/grants are authoritative; do not hand-create app tables from old plans.

4. Restore runtime ownership if root wrote files, then build the new release's caches as `emailsquire` using step 5 with unchanged `APP_KEY`. Add needed mail/portal/DNS settings, match helper options, and install updated shipped units/filter/jail. Ensure the rspamd map-directory move and DKIM timer are installed when upgrading from before phase 4.

5. Switch `current` using a new temporary symlink and rename on this layout:

   ```sh
   ln -s /opt/emailsquire/releases/REPLACE_WITH_NEW_RELEASE_ID /opt/emailsquire/current.next
   mv -Tf /opt/emailsquire/current.next /opt/emailsquire/current
   systemctl daemon-reload
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan up
   systemctl start emailsquire-fpm.service emailsquire-queue.service \
       emailsquire-schedule.timer emailsquire-maildir.timer \
       emailsquire-maildir-purge.timer emailsquire-dkim.timer
   ```

   FPM must restart with `opcache.validate_timestamps=0`; restarting the queue loads new code. Run both doctor views from step 13, check timers/HTTPS, and test console/portal sign-in, outgoing mail and DKIM signing. Keep queue retry-after above 100 seconds.

   Retain the previous release/backup until verified. Code rollback can switch the symlink and restart, but schema rollback compatibility is not guaranteed by sources. Decide whether to restore schemas or run a reviewed rollback for the specific migration; do not blindly run `migrate:rollback` on a live server.

## 17. Back up and restore

1. Include both schemas together in your mail-server backup so app state, including disabled-mailbox hashes, stays consistent with guide rows. Example:

   ```sh
   umask 077
   mariadb-dump --single-transaction --databases emailsquire mailserver \
       > /root/emailsquire-mailserver-backup.sql
   ```

   Substitute actual schema names. This covers only the DB. Backup storage, retention, encryption and off-host transfer are not defined here. Quiesce app/IMA writes and coordinate a consistent mail/config snapshot with your backup process; DB transactions do not snapshot Maildirs or keys.

2. Back up `/etc/emailsquire/.env` securely with `APP_KEY` and any previous keys. Include persistent storage, `/var/vmail` including `.emailsquire-trash`, `/var/lib/rspamd/dkim`, the selector map, effective rspamd/Dovecot/Postfix config and Apache/FPM/systemd/fail2ban config. Retain release identifier/artifact and DB account/grant definitions; schema dumps do not recreate accounts.

3. Test restore on an isolated host. Restore schemas, key, mail homes, keys/map and config from the same backup set, apply reviewed grants, restore ownership/modes, deploy the matching release and rebuild caches. Run doctor before enabling writers/helpers. Inspect pending tasks before starting timers: restored queues can replay work. Never generate a replacement `APP_KEY` for restored data.

## 18. Troubleshoot and recover

1. Inspect logs/units first:

   ```sh
   systemctl status emailsquire-fpm.service emailsquire-queue.service
   systemctl list-timers 'emailsquire-*'
   journalctl -u emailsquire-fpm.service -u emailsquire-queue.service \
       -u emailsquire-schedule.service -u emailsquire-maildir.service \
       -u emailsquire-maildir-purge.service -u emailsquire-dkim.service
   tail -n 100 /var/lib/emailsquire/storage/logs/laravel.log
   ```

   Use your host's configured Apache/Dovecot/Postfix/rspamd log paths (this vhost uses `/var/log/apache2/emailsquire-error.log`); the container's `/var/log/postfix.log` is test-only. Redact secrets before sharing logs.

2. For blank SPA, missing assets or PHP 500, check the build manifest, public root, FPM socket/group, storage/cache permissions and active caches. If code arrived before migrations, migrate under maintenance; do not give runtime DDL rights. Restart FPM after OPcache-cached deployments. A 413 means the 64 KiB request limit was exceeded.

3. For CSRF 419, cross-origin 403, cookies or passkey failures, check HTTPS, exact `APP_URL` scheme/host/port, host-only Secure cookie and same-origin API. Rebuild caches/restart after config changes. A hostname change affects old passkeys. `two_factor_required` sends you to My profile; `step_up_required` needs fresh password/code/passkey confirmation. TOTP codes are single-use per 30-second period; wait for the next code if just used.

4. For portal sign-in failures, check socket and Dovecot logs. Socket errors/timeouts fail closed. Repeated wrong attempts increase per-IP auth delay beyond the app's 10-second timeout. Check rate limits and both jails before assuming a bad password. If console/mail passwords coincide, choose mailbox sign-in. Forgotten mailbox passwords need a manager/admin reset, not emailed portal recovery.

5. For missing invitation/reset/security mail, check worker and local SMTP, then inspect failed jobs:

   ```sh
   cd /opt/emailsquire/current
   runuser -u emailsquire -- php artisan queue:failed
   ```

   Mail jobs try three times with 30-second and 120-second backoffs. After restoring Postfix, retry deliberately (this can send mail):

   ```sh
   runuser -u emailsquire -- php artisan queue:retry all
   ```

6. Inspect archive/restore task errors and fix the cause before retry:

   ```sh
   runuser -u emailsquire -- php artisan emailsquire:maildir-tasks
   runuser -u emailsquire -- php artisan emailsquire:maildir-tasks --retry=REPLACE_WITH_FAILED_ID
   ```

   It lists pending/running/failed maildir work and retries only failed maildir tasks. Address reuse is blocked while archiving is unfinished. Check mount, space, grants and writable paths; `Invalid cross-device link` often means a separate systemd trash bind mount. Do not delete tasks to bypass the guard.

   Before re-creating the mailbox for restoration, coordinate quiescence of delivery and login for that mailbox: hold or temporarily defer its mail, block new logins and end existing sessions so neither LMTP nor Dovecot can create its home. Keep those controls in place while re-creating the mailbox and until the helper has completed restoration. Choose the controls for your Postfix/Dovecot setup; disabling app sign-in alone does not stop delivery. Confirm the target home is absent, then queue:

   ```sh
   runuser -u emailsquire -- php artisan emailsquire:restore-maildir anna@example.org
   # Or choose a UTC YYYYmmddHHMMSS archive stamp:
   runuser -u emailsquire -- php artisan emailsquire:restore-maildir anna@example.org --from=20261011120000
   ```

   The helper refuses a target home already present when it checks, and its rename can race with Dovecot creating the home afterward. If a home has appeared, keep delivery/login quiescent, preserve any newly delivered mail and the archive, and reconcile them under an operator-controlled recovery procedure before retrying. Do not delete or overwrite the new home to make the helper proceed. Confirm task completion and restored paths/ownership before resuming delivery and logins and releasing held mail. Legacy spelling matters: `Anna@Example.org` uses `Example.org/Anna`; re-creation through the app uses lowercase `anna@example.org`. Restoring that archive needs operator spelling reconciliation; no automatic rename procedure is supplied. Preserve the archive and validate paths/ownership before changing it.

7. For DKIM failure, inspect helper journal, DNS card and tasks with a read-only administrator query. Check socket auth, column grants, keys, map directory and effective rspamd config. `maildir-tasks --retry` does not retry DKIM, and no DKIM retry CLI is supplied. Transient failures retry automatically; after fixing a permanent failure, request a fresh operation in the console. Publish the exact TXT record, recheck and activate; do not retire active keys or bypass the 15-minute wait. Verify actual message signatures.

8. For stale usage/DNS, check scheduler and queue. A manual usage refresh writes snapshots:

   ```sh
   runuser -u emailsquire -- php artisan emailsquire:refresh-usage
   ```

   DNS uses the system resolver. Set actual mail host/public IPs, check records in the DNS tab and use its recheck. SPF relying only on `include:` can be amber even if your policy is intentional; the check looks for server coverage. MTA-STS/autoconfig are informational. Catch-all requires `postfix.alias_maps` OK; receiving/send-only needs all step 15 changes.

9. For a lost console password, issue a setup link:

   ```sh
   runuser -u emailsquire -- php artisan emailsquire:create-admin you@example.org --reset
   ```

   Existing second factors still require confirmation afterward. If every factor/recovery code is lost, reset them first:

   ```sh
   runuser -u emailsquire -- php artisan emailsquire:reset-two-factor you@example.org
   ```

   This deletes passkeys/recovery codes, is audited and queues a security notice. Sign in with password or setup link and enrol again. It does not change mailbox passwords.

   The passkeys follow-ups leave real browser/device PIN and biometric verification open. Validate enrollment, sign-in and recovery with the browsers/devices your users will use; do not treat a virtual Chromium authenticator test as verification of every device.

10. Contributor tooling: `emailsquire:openapi [path]` exports `/api/v1`, default `resources/js/api/openapi.json`. It is not an install step; run it in a development checkout rather than root-owned deployment. Confirm commands/routes without operations:

    ```sh
    scripts/root-artisan list emailsquire
    scripts/root-artisan help emailsquire:doctor
    scripts/root-artisan route:list --path=api/v1 | head
    ```
