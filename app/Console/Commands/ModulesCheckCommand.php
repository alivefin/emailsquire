<?php

namespace App\Console\Commands;

use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;

final class ModulesCheckCommand extends Command
{
    protected $signature = 'emailsquire:modules:check';

    protected $description = 'List the modules in EMAILSQUIRE_MODULES and why any was refused; exits 1 if any was';

    public function handle(ModuleRegistry $modules): int
    {
        foreach ($modules->loaded() as $module) {
            $this->line("loaded {$module->manifest->name} {$module->manifest->version}");
        }
        foreach ($modules->refused() as $refusal) {
            $this->line('refused '.($refusal->name ?? $refusal->path).": {$refusal->reason}");
            $this->line("        {$refusal->detail}");
        }
        if ($modules->loaded() === [] && $modules->refused() === []) {
            $this->line('No modules listed.');
        }

        return $modules->refused() === [] ? self::SUCCESS : self::FAILURE;
    }
}
