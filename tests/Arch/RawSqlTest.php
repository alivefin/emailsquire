<?php

use Symfony\Component\Finder\Finder;

test('raw SQL only in repositories, queries and the doctor', function () {
    $allowed = [
        'app/MailServer/Repositories/',
        'app/MailServer/Queries/',
        'app/MailServer/SourceLock.php',
        'app/Doctor/',
        'app/Console/Commands/GrantsCommand.php',
    ];
    $pattern = '/DB::raw\(|->raw\(|whereRaw\(|selectRaw\(|orderByRaw\(|havingRaw\(|DB::statement\(|DB::select\(|DB::unprepared\(/';

    $offenders = [];
    foreach ((new Finder)->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
        $relative = 'app/'.str_replace('\\', '/', $file->getRelativePathname());
        if (! preg_match($pattern, (string) file_get_contents($file->getRealPath()))) {
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
