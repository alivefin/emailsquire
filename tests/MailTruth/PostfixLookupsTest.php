<?php

use App\MailServer\GuideSchema;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\GuideFixture;

pest()->group('mailtruth');

beforeEach(function () {
    GuideFixture::load('trixie');
});

/** Runs `postmap -q` against one of the guide's MariaDB map files, as Postfix would. */
function postmapQuery(string $key, string $map): string
{
    $process = new Process(['postmap', '-q', $key, "mysql:/etc/postfix/mariadb-{$map}.cf"]);
    $process->run();

    return trim($process->getOutput());
}

it('resolves the guide fixture through the postfix map files', function (string $key, string $map, string $expected) {
    expect(postmapQuery($key, $map))->toBe($expected);
})->with([
    'domain' => ['example.org', 'virtual-mailbox-domains', '1'],
    'mailbox' => ['john@example.org', 'virtual-mailbox-maps', '1'],
    'alias' => ['jack@example.org', 'virtual-alias-maps', 'john@example.org'],
    'email2email' => ['john@example.org', 'email2email', 'john@example.org'],
]);

it('sees an alias inserted by the app on the next lookup, without a reload', function () {
    expect(postmapQuery('jill@example.org', 'virtual-alias-maps'))->toBe('');

    DB::table(GuideSchema::table('virtual_aliases'))->insert([
        'domain_id' => 10,
        'source' => 'jill@example.org',
        'destination' => 'mary@example.org',
    ]);

    expect(postmapQuery('jill@example.org', 'virtual-alias-maps'))->toBe('mary@example.org');
});
