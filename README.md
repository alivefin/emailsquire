# EmailSquire

EmailSquire is a Laravel 13 / PHP 8.4 admin console with a React SPA for an existing mail server built with the workaround.org ISPmail guide, Debian Trixie edition: Postfix, Dovecot 2.4, MariaDB and rspamd.

You can manage domains, mailboxes, passwords, quotas, forwarding, aliases, catch-all forwarding and blocked addresses. The console also manages domain access, invitations, DKIM keys and DNS checks, and records changes in an audit log. Mailbox owners can change their password and manage forwarding and aliases when their domain allows it. Console accounts support TOTP and passkeys.

Run EmailSquire on the mail server itself. You need PHP 8.4 with FPM, CLI, MySQL, intl, mbstring, XML and curl support, an HTTPS virtual host, and systemd. Builds use Composer 2 and Node.js 22.12 or later in the 22 series with npm; production uses built assets and production PHP dependencies.

Follow the [installation and operator guide](docs/install.md).

For contributor tests, use a development checkout with `composer install` and `npm ci`. Read [CLAUDE.md](CLAUDE.md) first. Run these commands on a development machine; database fixtures and browser tests reset their test schemas.

```sh
docker compose up -d mariadb
vendor/bin/pest
npm run lint
npm run typecheck
npm test
```

The PHP tests use MariaDB, never SQLite. They do not require a frontend build. To test real mail services in the disposable Trixie container:

```sh
docker compose --profile mailtruth up -d --build --wait trixie-host
docker compose exec -T trixie-host vendor/bin/pest --group=mailtruth
```

For browser smoke tests and PHP quality checks:

```sh
npx playwright install chromium
docker compose up -d mariadb
npm run test:e2e
vendor/bin/pint --test
vendor/bin/phpstan analyse
vendor/bin/psalm --taint-analysis --no-cache
```

`npm run test:e2e` builds the SPA itself. Production assets are built with `npm ci && npm run build`.

License: MIT, as declared in `composer.json`.
