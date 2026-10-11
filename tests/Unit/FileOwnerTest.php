<?php

use App\Doctor\Support\FileOwner;

it('reads write access from the mode bits the way the kernel does for a non-root user', function (int $mode, int $fileUid, int $fileGid, bool $expected) {
    // The user: uid 100, primary group 100, also a member of group 200.
    expect(FileOwner::modeAllowsWrite($mode, $fileUid, $fileGid, 100, [100, 200]))->toBe($expected);
})->with([
    'owner with owner write' => [0644, 100, 0, true],
    'owner without owner write, even with group write' => [0464, 100, 100, false],
    'group member with group write' => [0664, 0, 200, true],
    'primary group with group write' => [0660, 0, 100, true],
    'group member without group write, even with other write' => [0646, 0, 200, false],
    'other with other write' => [0666, 0, 300, true],
    'other without other write' => [0664, 0, 300, false],
]);
