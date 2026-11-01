<?php

namespace EmailSquire\Modules\ThrowingRoutes;

use App\Models\User;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** A module whose routes file throws inside a group of its own. */
class ThrowingRoutesServiceProvider extends ServiceProvider implements Module
{
    public function routes(): ?string
    {
        return dirname(__DIR__).'/routes/api.php';
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
        return 'throwing-routes.';
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
