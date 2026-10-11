<?php

use App\Support\ConfigPath;

/*
 * Paths set in .env: an empty value means "not set" (the default), and a relative one is taken from the
 * app's base directory (the e2e run's fixture main.cf).
 */

it('resolves a configured path, its default when empty, and a relative one from the base directory', function (mixed $value, string $expected) {
    expect(ConfigPath::resolve($value, '/etc/postfix/main.cf'))->toBe(str_replace('{base}', base_path(), $expected));
})->with([
    'unset' => [null, '/etc/postfix/main.cf'],
    'empty' => ['', '/etc/postfix/main.cf'],
    'blank' => ['  ', '/etc/postfix/main.cf'],
    'absolute' => ['/srv/postfix/main.cf', '/srv/postfix/main.cf'],
    'relative' => ['tests/e2e/fixtures/postfix/main.cf', '{base}/tests/e2e/fixtures/postfix/main.cf'],
]);

it('falls back to /etc/postfix/main.cf when EMAILSQUIRE_POSTFIX_MAIN_CF is empty', function () {
    $saved = [$_ENV['EMAILSQUIRE_POSTFIX_MAIN_CF'] ?? null, $_SERVER['EMAILSQUIRE_POSTFIX_MAIN_CF'] ?? null, getenv('EMAILSQUIRE_POSTFIX_MAIN_CF')];
    $_ENV['EMAILSQUIRE_POSTFIX_MAIN_CF'] = $_SERVER['EMAILSQUIRE_POSTFIX_MAIN_CF'] = '';
    putenv('EMAILSQUIRE_POSTFIX_MAIN_CF=');
    try {
        $config = require base_path('config/emailsquire.php');
    } finally {
        foreach ([&$_ENV, &$_SERVER] as $i => &$store) {
            if ($saved[$i] === null) {
                unset($store['EMAILSQUIRE_POSTFIX_MAIN_CF']);
            } else {
                $store['EMAILSQUIRE_POSTFIX_MAIN_CF'] = $saved[$i];
            }
        }
        putenv($saved[2] === false ? 'EMAILSQUIRE_POSTFIX_MAIN_CF' : "EMAILSQUIRE_POSTFIX_MAIN_CF={$saved[2]}");
    }

    expect($config['paths']['postfix_main_cf'])->toBe('/etc/postfix/main.cf');
});
