<?php

namespace EmailSquire\Modules\OddValues;

use App\Models\User;
use App\Modules\Module;
use App\Modules\NavItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** A module that names a routes file it doesn't have and a doctor check that doesn't exist, and returns lists with elements of the wrong type. */
class OddValuesServiceProvider extends ServiceProvider implements Module
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
        return ['EmailSquire\\Modules\\OddValues\\NoSuchCheck', 42];
    }

    public function auditGroups(): array
    {
        return [];
    }

    public function auditPrefix(): string
    {
        return 'odd-values.';
    }

    public function navigation(User $user): array
    {
        return ['not a nav item', new NavItem('ok', '/odd-values', 'odd-values:nav.ok'), ['key' => 'array']];
    }

    public function dashboardCards(User $user): array
    {
        return ['ok', 42, null];
    }
}
