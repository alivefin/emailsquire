<?php

arch()->preset()->php();
arch()->preset()->security();
arch('no process execution in app code')->expect('App')->not->toUse('Symfony\Component\Process');
arch('env only in config')->expect('App')->not->toUse('env');
// Controllers, requests, resources and policies may type-hint guide models for route binding and
// serialisation; writes stay in App\MailServer\Repositories (Global Constraints).
arch('guide models stay out of the rest of the app')->expect('App\MailServer\Models')->toOnlyBeUsedIn([
    'App\MailServer', 'App\Http\Controllers', 'App\Http\Resources', 'App\Http\Requests', 'App\Policies', 'App\Auth',
]);
