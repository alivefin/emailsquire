<?php

namespace App\Providers;

use App\MailServer\Dovecot\DoveadmClient;
use App\MailServer\Dovecot\DovecotAuthClient;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(DoveadmClient::class, fn () => new DoveadmClient(
            config()->string('emailsquire.doveadm.url'),
            config()->string('emailsquire.doveadm.api_key', '') ?: null,
            config()->integer('emailsquire.doveadm.timeout'),
        ));
        $this->app->singleton(DovecotAuthClient::class, fn () => new DovecotAuthClient(
            config()->string('emailsquire.dovecot_auth.socket'),
            config()->integer('emailsquire.dovecot_auth.timeout'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
