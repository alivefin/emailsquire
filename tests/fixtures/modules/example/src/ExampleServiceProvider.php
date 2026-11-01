<?php

namespace EmailSquire\Modules\Example;

use App\Models\User;
use App\Modules\Module;
use App\Modules\NavItem;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\ServiceProvider;

/** The fixture module the module-platform tests load. */
class ExampleServiceProvider extends ServiceProvider implements Module
{
    public function routes(): ?string
    {
        return dirname(__DIR__).'/routes/api.php';
    }

    public function migrations(): ?string
    {
        return dirname(__DIR__).'/database/migrations';
    }

    public function schedule(Schedule $schedule): void
    {
        $schedule->call(fn () => null)->name('example:tick')->hourly();
    }

    public function doctorChecks(): array
    {
        return [ExampleCheck::class];
    }

    public function auditGroups(): array
    {
        return ['example' => ['example.noted']];
    }

    public function auditPrefix(): string
    {
        return 'example.';
    }

    public function navigation(User $user): array
    {
        return $user->is_server_admin ? [new NavItem('notes', '/example', 'example:nav.notes')] : [];
    }

    public function dashboardCards(User $user): array
    {
        return ['summary'];
    }
}
