<?php

namespace EmailSquire\Modules\BrokenCheck;

use App\Models\User;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** A module whose doctor check cannot be resolved. */
class BrokenCheckServiceProvider extends ServiceProvider implements Module
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
        return [ExampleBrokenCheck::class];
    }

    public function auditGroups(): array
    {
        return [];
    }

    public function auditPrefix(): string
    {
        return 'broken-check.';
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
