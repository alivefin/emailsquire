<?php

/** @return array<string, string> */
function flattenLang(array $lines, string $prefix = ''): array
{
    $flat = [];
    foreach ($lines as $key => $value) {
        is_array($value)
            ? $flat += flattenLang($value, "{$prefix}{$key}.")
            : $flat["{$prefix}{$key}"] = (string) $value;
    }

    return $flat;
}

/** @return list<string> */
function langPlaceholders(string $text): array
{
    preg_match_all('/:[A-Za-z_]+/', $text, $m);
    $names = array_values(array_unique($m[0]));
    sort($names);

    return $names;
}

it('has every English lang file in Finnish', function () {
    $en = array_map('basename', glob(dirname(__DIR__, 2).'/lang/en/*.php'));
    $fi = array_map('basename', glob(dirname(__DIR__, 2).'/lang/fi/*.php'));
    sort($en);
    sort($fi);

    expect($en)->not->toBeEmpty()->and($fi)->toBe($en);
});

it('keeps the keys and :placeholders of lang/en and lang/fi identical, with no empty strings', function (string $file) {
    $root = dirname(__DIR__, 2).'/lang';
    $en = flattenLang(require "{$root}/en/{$file}");
    $fi = flattenLang(require "{$root}/fi/{$file}");

    expect(array_keys($fi))->toEqualCanonicalizing(array_keys($en));
    foreach ($en as $key => $text) {
        expect(trim($text))->not->toBe('', "en {$file} {$key} is empty");
        expect(trim($fi[$key]))->not->toBe('', "fi {$file} {$key} is empty");
        expect(langPlaceholders($fi[$key]))->toBe(langPlaceholders($text), "placeholders differ in {$file} {$key}");
    }
})->with(fn () => array_map('basename', glob(dirname(__DIR__, 2).'/lang/en/*.php')));
