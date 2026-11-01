<?php

namespace EmailSquire\Modules\Throws;

use App\Models\User;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/** A module whose registration fails. */
class ThrowsServiceProvider extends ServiceProvider implements Module
{
    public function register(): void
    {
        throw new RuntimeException('The throws fixture refuses to register.');
    }

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
        return 'throws.';
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
