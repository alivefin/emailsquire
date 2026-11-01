<?php

namespace App\Modules;

use App\Doctor\Check;
use App\Models\User;
use Illuminate\Console\Scheduling\Schedule;

/**
 * What a module's service provider offers the core, on top of `ServiceProvider`. The provider class
 * named in `emailsquire-module.json` must extend `Illuminate\Support\ServiceProvider` and implement this.
 */
interface Module
{
    /** The absolute path of the module's API routes file, served under `api/v1/modules/<name>`, or null. */
    public function routes(): ?string;

    /** The absolute path of the module's migrations directory, or null. */
    public function migrations(): ?string;

    public function schedule(Schedule $schedule): void;

    /** @return list<class-string<Check>> */
    public function doctorChecks(): array;

    /** @return array<string, list<string>> audit actions by group, each starting with auditPrefix() */
    public function auditGroups(): array;

    /** The prefix every audit action of the module starts with, e.g. `insights.`. */
    public function auditPrefix(): string;

    /** @return list<NavItem> the console navigation items $user may see */
    public function navigation(User $user): array;

    /** @return list<string> the keys of the dashboard cards $user may see */
    public function dashboardCards(User $user): array;
}
