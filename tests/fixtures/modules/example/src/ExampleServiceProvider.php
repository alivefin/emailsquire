<?php

namespace EmailSquire\Modules\Example;

use App\Models\User;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** The fixture module the module-platform tests load. */
class ExampleServiceProvider extends ServiceProvider implements Module
{
    public function routes(): ?string
    {
        return null;
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
        return 'example.';
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
