<?php

use Symfony\Component\Finder\Finder;

// Any builder `...Raw(` call (whereRaw, orWhereRaw, joinRaw, fromRaw, groupByRaw, ...) and the DB facade's
// direct statement methods, matched case-insensitively.
const RAW_SQL_PATTERN = '/\\w*Raw\\(|DB::(?:raw|statement|select|selectOne|insert|update|delete|cursor|unprepared)\\(/i';

test('the raw SQL pattern catches the forms it must', function (string $sample) {
    expect(preg_match(RAW_SQL_PATTERN, $sample))->toBe(1);
})->with([
    '->orWhereRaw(', '->selectRaw(', '->joinRaw(', '->fromRaw(', '->groupByRaw(', 'DB::raw(',
    'DB::insert(', 'DB::selectOne(', 'DB::update(', 'DB::delete(', 'DB::cursor(', 'DB::STATEMENT(',
]);

test('the raw SQL pattern ignores ordinary builder calls', function () {
    expect(preg_match(RAW_SQL_PATTERN, '->where(\'a\', 1)->orderBy(\'id\')->get()'))->toBe(0);
});

test('raw SQL only in repositories, queries and the doctor', function () {
    $allowed = [
        'app/MailServer/Repositories/',
        'app/MailServer/Queries/',
        'app/MailServer/SourceLock.php',
        'app/Doctor/',
        'app/Console/Commands/GrantsCommand.php',
    ];

    $offenders = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        if (! preg_match(RAW_SQL_PATTERN, (string) file_get_contents($file->getRealPath()))) {
            continue;
        }
        $ok = false;
        foreach ($allowed as $prefix) {
            if ($relative === $prefix || (str_ends_with($prefix, '/') && str_starts_with($relative, $prefix))) {
                $ok = true;
            }
        }
        if (! $ok) {
            $offenders[] = $relative;
        }
    }

    expect($offenders)->toBe([]);
});
