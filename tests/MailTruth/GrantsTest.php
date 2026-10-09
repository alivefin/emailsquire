<?php

use Illuminate\Support\Facades\Artisan;

pest()->group('mailtruth');

it('prints the grants through the migrator connection as root over the socket', function () {
    $exit = Artisan::call('emailsquire:grants', ['--database' => 'migrator']);
    $output = Artisan::output();

    expect($exit)->toBe(0, $output)
        ->and($output)->toContain('`emailsquire_test`.`audit_log`');
});
