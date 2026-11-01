<?php

namespace App\Doctor\Checks;

use App\Doctor\BaseCheck;
use App\Doctor\CheckResult;
use App\Doctor\Status;
use App\Modules\ModuleLoader;
use App\Modules\ModuleRegistry;

/**
 * Every module the operator listed loaded, the ownership check is on where it must be, and each module's
 * assets are linked into public/modules (run `emailsquire:modules:link` to fix that). The link location
 * is public_path(), so tests move it with `usePublicPath()`.
 */
final class ModulesLoaded extends BaseCheck
{
    public function __construct(private readonly ModuleRegistry $modules) {}

    public function id(): string
    {
        return 'modules.loaded';
    }

    public function title(): string
    {
        return 'Modules loaded';
    }

    public function run(): CheckResult
    {
        $loaded = $this->modules->loaded();
        $refused = $this->modules->refused();
        $ownershipOff = ! config()->boolean('emailsquire.modules_require_root_owner') && ! ModuleLoader::ownershipMayBeOff();

        if ($loaded === [] && $refused === [] && ! $ownershipOff) {
            return $this->result(Status::Ok, 'No modules listed');
        }

        $items = [];
        $unlinked = [];
        foreach ($loaded as $module) {
            $items[] = "{$module->manifest->name} {$module->manifest->version}";
            if ($this->linkProblem($module->manifest->name, $module->manifest->dir) !== null) {
                $unlinked[] = $module->manifest->name;
            }
        }
        foreach ($refused as $refusal) {
            $items[] = "{$refusal->path}: {$refusal->reason}";
        }

        if ($refused !== []) {
            return $this->result(Status::Fail, count($refused).' listed module(s) refused; see `emailsquire:modules:check`', $items);
        }
        if ($ownershipOff) {
            return $this->result(Status::Fail, 'The module ownership check is off outside testing, e2e and local; it is enforced anyway. Remove EMAILSQUIRE_MODULES_REQUIRE_ROOT_OWNER=false', $items);
        }
        if ($unlinked !== []) {
            return $this->result(Status::Warn, 'Asset link missing or stale for '.implode(', ', $unlinked).'; run `emailsquire:modules:link`', $items);
        }

        return $this->result(Status::Ok, count($loaded).' module(s) loaded', $items);
    }

    /** What is wrong with the module's asset link, or null when it is right or the module has no public directory. */
    private function linkProblem(string $name, string $dir): ?string
    {
        $target = $dir.'/public';
        if (! is_dir($target)) {
            return null;
        }
        $link = public_path("modules/{$name}");
        if (! is_link($link)) {
            return 'missing';
        }

        return readlink($link) === $target ? null : 'stale';
    }
}
