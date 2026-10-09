<?php

use App\Doctor\Support\PostfixConfig;

function postfixConfigFile(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'maincf');
    file_put_contents($path, $contents);

    return $path;
}

it('joins continuation lines', function () {
    $path = postfixConfigFile("virtual_alias_maps = mysql:/a.cf,\n    mysql:/b.cf\nother = 1\n");

    $config = PostfixConfig::fromFile($path);

    expect($config->mapFiles('virtual_alias_maps'))->toBe(['/a.cf', '/b.cf'])
        ->and($config->get('other'))->toBe('1');
});

it('ignores comments and does not expand variables', function () {
    $path = postfixConfigFile("# virtual_alias_maps = mysql:/old.cf\nmyorigin = \$mydomain\n");

    $config = PostfixConfig::fromFile($path);

    expect($config->get('virtual_alias_maps'))->toBeNull()
        ->and($config->get('myorigin'))->toBe('$mydomain');
});

it('returns null and no files for a missing parameter or file', function () {
    $config = PostfixConfig::fromFile(postfixConfigFile("a = b\n"));

    expect($config->get('nope'))->toBeNull()
        ->and($config->mapFiles('nope'))->toBe([])
        ->and(PostfixConfig::fromFile('/nonexistent/main.cf')->get('a'))->toBeNull();
});

it('only returns mysql map files, split on commas or whitespace', function () {
    $path = postfixConfigFile("m = hash:/etc/aliases mysql:/a.cf mysql:/b.cf,mysql:/c.cf\n");

    expect(PostfixConfig::fromFile($path)->mapFiles('m'))->toBe(['/a.cf', '/b.cf', '/c.cf']);
});
