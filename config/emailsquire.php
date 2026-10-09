<?php

return [

    'guide_schema' => env('EMAILSQUIRE_GUIDE_SCHEMA', 'mailserver'),
    'quota_default_bytes' => 5368709120,
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

];
