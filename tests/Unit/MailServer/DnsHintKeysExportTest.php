<?php

use App\MailServer\Dns\DnsHealth;

/*
 * The SPA's copy of DnsHealth::HINT_KEYS. A Vitest test (resources/js/features/dns/hints.test.ts) checks
 * that every key in it has a translation in English and Finnish (the `dns` catalogue's `hint` group). This
 * test keeps the copy equal to the PHP constant; when it fails, regenerate it:
 *
 *   php -r 'require "vendor/autoload.php"; file_put_contents("resources/js/api/dnsHintKeys.json", json_encode(App\MailServer\Dns\DnsHealth::HINT_KEYS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");'
 *
 * and word each new key in resources/js/i18n/{en,fi}/dns.json.
 */
it('exports the DNS hint keys to the SPA unchanged', function () {
    $path = base_path('resources/js/api/dnsHintKeys.json');
    $expected = json_encode(DnsHealth::HINT_KEYS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    expect(file_get_contents($path))->toBe($expected, 'resources/js/api/dnsHintKeys.json is out of date; see the comment above this test.');
});
