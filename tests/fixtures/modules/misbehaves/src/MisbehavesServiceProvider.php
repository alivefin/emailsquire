<?php

namespace EmailSquire\Modules\Misbehaves;

use App\Models\User;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** A module that loads, and then throws from everything the core calls after loading. */
class MisbehavesServiceProvider extends ServiceProvider implements Module
{
    public function routes(): ?string
    {
        throw new RuntimeException('The misbehaves fixture has no routes().');
    }

    public function migrations(): ?string
    {
        return null;
    }

    public function schedule(Schedule $schedule): void
    {
        throw new RuntimeException('The misbehaves fixture has no schedule().');
    }

    public function doctorChecks(): array
    {
        throw new RuntimeException('The misbehaves fixture has no doctorChecks().');
    }

    public function auditGroups(): array
    {
        return [];
    }

    public function auditPrefix(): string
    {
        return 'misbehaves.';
    }

    public function navigation(User $user): array
    {
        throw new RuntimeException('The misbehaves fixture has no navigation().');
    }

    public function dashboardCards(User $user): array
    {
        throw new RuntimeException('The misbehaves fixture has no dashboardCards().');
    }
}
