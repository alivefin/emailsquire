<?php

const UNIT_DIR = __DIR__.'/../../deploy/systemd';

/** @return array<string, array<string, array<string, string>>> */
function systemdUnits(string $extension): array
{
    $units = [];
    foreach (glob(UNIT_DIR."/*.{$extension}") ?: [] as $path) {
        $parsed = parse_ini_file($path, true, INI_SCANNER_RAW);
        expect($parsed)->toBeArray();
        $units[basename($path)] = $parsed;
    }

    return $units;
}

it('ships the three service and timer pairs and the queue worker', function () {
    expect(array_keys(systemdUnits('service')))->toBe([
        'emailsquire-maildir-purge.service', 'emailsquire-maildir.service', 'emailsquire-queue.service', 'emailsquire-schedule.service',
    ]);
});

it('gives every service a timer of the same name, except long-running Type=simple ones', function () {
    foreach (systemdUnits('service') as $service => $unit) {
        if ($unit['Service']['Type'] === 'simple') {
            expect(UNIT_DIR.'/'.basename($service, '.service').'.timer')->not->toBeFile();

            continue;
        }
        expect(UNIT_DIR.'/'.basename($service, '.service').'.timer')->toBeFile();
    }
});

it('runs files that exist in the repo', function () {
    foreach (systemdUnits('service') as $name => $unit) {
        expect($unit['Service']['ExecStart'])->toMatch('#^/usr/bin/php /opt/emailsquire/current/\S+#', $name);
        preg_match('#/opt/emailsquire/current/(\S+)#', $unit['Service']['ExecStart'], $match);
        expect(base_path($match[1]))->toBeFile();
    }
});

it('runs the maildir helper as vmail with unix sockets only', function (string $service, string $mode) {
    $unit = systemdUnits('service')[$service]['Service'];

    expect($unit['User'])->toBe('vmail')
        ->and($unit['Group'])->toBe('vmail')
        ->and($unit['Type'])->toBe('oneshot')
        ->and($unit['RestrictAddressFamilies'])->toBe('AF_UNIX')
        ->and($unit['ReadWritePaths'])->toBe('/var/vmail')
        ->and($unit['ExecStart'])->toBe("/usr/bin/php /opt/emailsquire/current/helpers/maildir.php {$mode}");
})->with([
    ['emailsquire-maildir.service', 'run'],
    ['emailsquire-maildir-purge.service', 'purge'],
]);

it('runs the scheduler as emailsquire', function () {
    $unit = systemdUnits('service')['emailsquire-schedule.service']['Service'];

    expect($unit['User'])->toBe('emailsquire')
        ->and($unit['ExecStart'])->toBe('/usr/bin/php /opt/emailsquire/current/artisan schedule:run')
        ->and($unit['ReadWritePaths'])->toBe('/var/lib/emailsquire/storage')
        ->and($unit['RestrictAddressFamilies'])->toBe('AF_UNIX AF_INET AF_INET6');
});

it('runs the queue worker as a restarting emailsquire service that may reach SMTP on 127.0.0.1', function () {
    $unit = systemdUnits('service')['emailsquire-queue.service']['Service'];

    expect($unit['Type'])->toBe('simple')
        ->and($unit['User'])->toBe('emailsquire')
        ->and($unit['Restart'])->toBe('always')
        ->and($unit['ExecStart'])->toBe('/usr/bin/php /opt/emailsquire/current/artisan queue:work --sleep=3 --max-time=3600')
        ->and($unit['ReadWritePaths'])->toBe('/var/lib/emailsquire/storage')
        ->and($unit['RestrictAddressFamilies'])->toContain('AF_INET')
        ->and(systemdUnits('service')['emailsquire-queue.service']['Install']['WantedBy'])->toBe('multi-user.target');
});

it('applies the hardening block and never denies write+execute memory', function () {
    foreach (systemdUnits('service') as $name => $parsed) {
        $unit = $parsed['Service'];
        expect($unit)->not->toHaveKey('MemoryDenyWriteExecute');
        foreach (['NoNewPrivileges' => 'yes', 'ProtectSystem' => 'strict', 'ProtectHome' => 'yes', 'PrivateTmp' => 'yes',
            'PrivateDevices' => 'yes', 'ProtectKernelTunables' => 'yes', 'ProtectKernelModules' => 'yes',
            'ProtectControlGroups' => 'yes', 'RestrictNamespaces' => 'yes', 'LockPersonality' => 'yes',
            'SystemCallArchitectures' => 'native'] as $key => $value) {
            expect($unit[$key] ?? null)->toBe($value, "{$name} {$key}");
        }
        expect($unit)->toHaveKey('CapabilityBoundingSet');
    }
});

it('schedules the timers', function () {
    $timers = systemdUnits('timer');

    expect($timers['emailsquire-maildir.timer']['Timer'])->toMatchArray(['OnBootSec' => '1min', 'OnUnitActiveSec' => '1min'])
        ->and($timers['emailsquire-maildir-purge.timer']['Timer'])->toMatchArray(['OnCalendar' => 'daily', 'Persistent' => 'true'])
        ->and($timers['emailsquire-schedule.timer']['Timer'])->toMatchArray(['OnCalendar' => 'minutely']);
    foreach ($timers as $timer) {
        expect($timer['Install']['WantedBy'])->toBe('timers.target');
    }
});

it('fires the minute timers within a second, so schedule:run never skips a */15 tick', function (string $timer) {
    // systemd's default AccuracySec=1min can start the minutely run in the next minute.
    expect(systemdUnits('timer')[$timer]['Timer']['AccuracySec'] ?? null)->toBe('1s');
})->with(['emailsquire-schedule.timer', 'emailsquire-maildir.timer']);
