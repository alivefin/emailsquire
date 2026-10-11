<?php

arch()->preset()->php();
// LoginThrottle rebuilds ThrottleRequests' md5 bucket key to clear it; md5 is no hash of a secret there.
arch()->preset()->security()->ignoring('App\Auth\LoginThrottle');
arch('no process execution in app code')->expect('App')->not->toUse('Symfony\Component\Process');
arch('env only in config')->expect('App')->not->toUse('env');
// Controllers, requests, resources and policies may type-hint guide models for route binding and
// serialisation; writes stay in App\MailServer\Repositories (Global Constraints).
arch('guide models stay out of the rest of the app')->expect('App\MailServer\Models')->toOnlyBeUsedIn([
    'App\MailServer', 'App\Http\Controllers', 'App\Http\Resources', 'App\Http\Requests', 'App\Policies', 'App\Auth',
]);
arch('controllers do not use the DB facade')->expect('App\Http')->not->toUse('Illuminate\Support\Facades\DB');
arch('repositories are only used from controllers, commands and MailServer')->expect('App\MailServer\Repositories')->toOnlyBeUsedIn([
    'App\MailServer', 'App\Http\Controllers', 'App\Console\Commands',
]);
arch('the helpers are framework-free')->expect('EmailSquire\Helpers')->not->toUse(['Illuminate', 'App', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen']);
arch('the dkim helper is framework-free')->expect('EmailSquire\Helpers\Dkim')->not->toUse([
    'Illuminate', 'App', 'Symfony', 'exec', 'shell_exec', 'system', 'passthru', 'proc_open', 'popen', 'pcntl_exec', 'getenv', 'parse_ini_file',
]);
arch('the dkim helper stands alone')->expect('EmailSquire\Helpers\Dkim')->toOnlyUse([
    'EmailSquire\Helpers\Dkim', 'PDO', 'PDOException', 'OpenSSLAsymmetricKey', 'Closure', 'RuntimeException', 'InvalidArgumentException', 'Throwable',
]);
