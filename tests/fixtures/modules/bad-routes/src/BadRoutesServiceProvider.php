<?php

namespace EmailSquire\Modules\BadRoutes;

use App\Models\User;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** A module whose routes file does not parse. */
class BadRoutesServiceProvider extends ServiceProvider implements Module
{
    public function routes(): ?string
    {
        return dirname(__DIR__).'/routes/api.inc';
    }

    public function migrations(): ?string
    {
        return null;
    }

    public function schedule(Schedule $schedule): void {}

    public function doctorChecks(): array
    {
        return [];
    }

    public function auditGroups(): array
    {
        return [];
    }

    public function auditPrefix(): string
    {
        return 'bad-routes.';
    }

    public function navigation(User $user): array
    {
        return [];
    }

    public function dashboardCards(User $user): array
    {
        return [];
    }
}
