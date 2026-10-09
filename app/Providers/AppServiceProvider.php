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
            $this->apiKey(),
            config()->integer('emailsquire.doveadm.timeout'),
        ));
        $this->app->singleton(DovecotAuthClient::class, fn () => new DovecotAuthClient(
            config()->string('emailsquire.dovecot_auth.socket'),
            config()->integer('emailsquire.dovecot_auth.timeout'),
        ));
    }

    private function apiKey(): ?string
    {
        $key = config('emailsquire.doveadm.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
