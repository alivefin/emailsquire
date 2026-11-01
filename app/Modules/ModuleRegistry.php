<?php

namespace App\Modules;

use App\Doctor\Check;

/** The modules this process loaded, and the ones it refused. Built once, by ModuleLoader. */
final class ModuleRegistry
{
    /**
     * @param  list<LoadedModule>  $loaded
     * @param  list<ModuleRefusal>  $refused
     */
    public function __construct(
        private readonly array $loaded = [],
        private readonly array $refused = [],
    ) {}

    /** @return list<LoadedModule> */
    public function loaded(): array
    {
        return $this->loaded;
    }

    /** @return list<ModuleRefusal> */
    public function refused(): array
    {
        return $this->refused;
    }

    public function find(string $name): ?LoadedModule
    {
        foreach ($this->loaded as $module) {
            if ($module->manifest->name === $name) {
                return $module;
            }
        }

        return null;
    }

    /** @return list<class-string<Check>> every loaded module's doctor checks */
    public function doctorChecks(): array
    {
        $checks = [];
        foreach ($this->loaded as $module) {
            array_push($checks, ...$module->provider->doctorChecks());
        }

        return $checks;
    }

    /** @return array<string, list<string>> every loaded module's audit actions by group */
    public function auditGroups(): array
    {
        $groups = [];
        foreach ($this->loaded as $module) {
            foreach ($module->provider->auditGroups() as $group => $actions) {
                $groups[$group] = [...$groups[$group] ?? [], ...$actions];
            }
        }

        return $groups;
    }
}
