<?php

it('the shipped filter matches the logged failure line', function () {
    $conf = file_get_contents(dirname(__DIR__, 2).'/deploy/fail2ban/filter.d/emailsquire.conf');
    expect(preg_match('/^failregex\s*=\s*(.+)$/m', (string) $conf, $m))->toBe(1);

    $regex = str_replace('<HOST>', '(?P<host>\S+)', trim($m[1]));
    $line = '[2026-10-10 12:00:00] production.WARNING: emailsquire auth failure user=a@b.c ip=203.0.113.9';

    expect(preg_match('~'.$regex.'~', $line, $hit))->toBe(1);
    expect($hit['host'])->toBe('203.0.113.9');
});

it('the jail watches the application log with the agreed limits', function () {
    $jail = (string) file_get_contents(dirname(__DIR__, 2).'/deploy/fail2ban/jail.d/emailsquire.conf');

    expect($jail)->toContain('filter = emailsquire')
        ->toContain('logpath = /var/lib/emailsquire/storage/logs/laravel.log')
        ->toContain('maxretry = 10')
        ->toContain('findtime = 10m');
});
