<?php

namespace EmailSquire\Modules\AuditCorePrefix;

use App\Models\User;
use App\Modules\Module;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** A module whose audit registration is refused. */
class AuditCorePrefixServiceProvider extends ServiceProvider implements Module
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
        return ['mine' => ['user.mine']];
    }

    public function auditPrefix(): string
    {
        return 'user.';
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
