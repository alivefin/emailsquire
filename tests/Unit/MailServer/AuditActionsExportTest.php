<?php

use App\MailServer\AuditActions;

/*
 * The SPA's copy of AuditActions::GROUPS. The activity log's group filter reads it, and a Vitest test
 * (activityText.coverage.test.ts) checks that every action in it has a translated sentence in English
 * and Finnish. This test keeps the copy equal to the PHP constant; when it fails, regenerate it:
 *
 *   php -r 'require "vendor/autoload.php"; file_put_contents("resources/js/api/auditActions.json", json_encode(App\MailServer\AuditActions::GROUPS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");'
 *
 * and give each new action a sentence in resources/js/features/dashboard/activityText.ts.
 */
it('exports the audit action groups to the SPA unchanged', function () {
    $path = base_path('resources/js/api/auditActions.json');
    $expected = json_encode(AuditActions::GROUPS, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";

    expect(file_get_contents($path))->toBe($expected, 'resources/js/api/auditActions.json is out of date; see the comment above this test.');
});
