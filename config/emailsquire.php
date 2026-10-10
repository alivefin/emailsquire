<?php

return [

    'guide_schema' => env('EMAILSQUIRE_GUIDE_SCHEMA', 'mailserver'),
    'quota_default_bytes' => 5368709120,
    'app_user' => 'emailsquire', // OS user that runs PHP-FPM, the queue and the scheduler
    'vmail_user' => 'vmail',
    'rspamd_user' => '_rspamd',
    'paths' => [
        'postfix_main_cf' => env('EMAILSQUIRE_POSTFIX_MAIN_CF', '/etc/postfix/main.cf'),
        'mail_root' => env('EMAILSQUIRE_MAIL_ROOT', '/var/vmail'),
        'trash_dir' => env('EMAILSQUIRE_TRASH_DIR', '/var/vmail/.emailsquire-trash'),
        'dkim_dir' => env('EMAILSQUIRE_DKIM_DIR', '/var/lib/rspamd/dkim'),
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

    'hibp' => env('EMAILSQUIRE_HIBP', true), // refuse console passwords found in Have I Been Pwned
    'admin_setup_expire_minutes' => 4320, // a create-admin setup link lives 72 h
    'trash_retention_days' => 30,

    'testing' => [
        // Laravel skips CSRF checks while unit testing. Only the security tests set this true, to prove
        // the check is enforced; nothing else should touch it.
        'enforce_csrf' => false,
    ],

];
