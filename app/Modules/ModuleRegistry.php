<?php

namespace App\Modules;

use App\Doctor\Check;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use Throwable;

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

    /**
     * Every loaded module's doctor checks, in load order. A module whose doctorChecks() throws, or names
     * something that is not a doctor check class, gets one Fail result (`modules.<name>.checks`) in place of
     * what it could not give, so the rest of doctor still runs.
     *
     * @return list<class-string<Check>|CheckResult>
     */
    public function doctorChecks(): array
    {
        $checks = [];
        foreach ($this->loaded as $module) {
            $name = $module->manifest->name;
            $fail = fn (string $detail) => new CheckResult("modules.{$name}.checks", Status::Fail, "Module {$name}: doctor checks", $detail);
            try {
                $classes = $module->provider->doctorChecks();
            } catch (Throwable $e) {
                $checks[] = $fail('doctorChecks() failed: '.$e::class.': '.$e->getMessage());

                continue;
            }
            $wrong = [];
            foreach ($classes as $class) {
                $check = self::checkClass($class);
                if ($check !== null) {
                    $checks[] = $check;
                } else {
                    $wrong[] = self::describe($class);
                }
            }
            if ($wrong !== []) {
                $checks[] = $fail('doctorChecks() named what is not a doctor check class: '.implode(', ', $wrong).'.');
            }
        }

        return $checks;
    }

    /**
     * $class, when it names a doctor check class; a module may break its contract.
     *
     * @return class-string<Check>|null
     */
    private static function checkClass(mixed $class): ?string
    {
        return is_string($class) && is_a($class, Check::class, true) ? $class : null;
    }

    /** What a module named in place of a check class, for the doctor's detail. */
    private static function describe(mixed $value): string
    {
        return is_string($value) ? $value : get_debug_type($value);
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

    /** @return list<string> every action of every loaded module */
    public function moduleActions(): array
    {
        return array_merge([], ...array_values($this->auditGroups()));
    }
}
