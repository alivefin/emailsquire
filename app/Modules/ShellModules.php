<?php

namespace App\Modules;

/** The loaded modules' assets as the SPA shell references them: stylesheet URLs and import entries. */
final class ShellModules
{
    public function __construct(private readonly ModuleRegistry $registry) {}

    /** @return list<string> a stylesheet URL for every module that ships one */
    public function styles(): array
    {
        $styles = [];
        foreach ($this->registry->loaded() as $module) {
            if ($module->manifest->style !== null) {
                $styles[] = $this->url($module->manifest, $module->manifest->style);
            }
        }

        return $styles;
    }

    /** @return list<array{name: string, entry: string}> every module that ships an entry script */
    public function entries(): array
    {
        $entries = [];
        foreach ($this->registry->loaded() as $module) {
            if ($module->manifest->entry !== null) {
                $entries[] = ['name' => $module->manifest->name, 'entry' => $this->url($module->manifest, $module->manifest->entry)];
            }
        }

        return $entries;
    }

    /** The version is not validated by the manifest, so it is encoded for the query string. */
    private function url(ModuleManifest $manifest, string $path): string
    {
        return "/modules/{$manifest->name}/{$path}?v=".rawurlencode($manifest->version);
    }
}
