<?php

namespace App\Console\Commands;

use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;

/**
 * Links each loaded module's public/ directory to public/modules/<name>. Runs as root, so it only ever
 * touches direct children of public/modules, replaces links atomically (new link, then rename over the old),
 * and never deletes or replaces anything that is not a symlink.
 */
final class ModulesLinkCommand extends Command
{
    protected $signature = 'emailsquire:modules:link {--public= : The public directory (default: the application\'s)}';

    protected $description = 'Link loaded modules\' assets into public/modules and remove links of modules that are not loaded';

    public function handle(ModuleRegistry $registry): int
    {
        $option = $this->option('public');
        $public = rtrim(is_string($option) && $option !== '' ? $option : public_path(), '/');
        $base = $public.'/modules';

        if (is_link($base) || (file_exists($base) && ! is_dir($base))) {
            $this->error("{$base} exists and is not a plain directory; refusing to use it.");

            return self::FAILURE;
        }

        $wanted = [];
        foreach ($registry->loaded() as $module) {
            $name = $module->manifest->name;
            $target = $module->manifest->dir.'/public';
            if (! is_dir($target)) {
                $this->line("skipped {$name}: no public directory");

                continue;
            }
            $wanted[$name] = $target;
        }
        $loadedNames = array_map(fn ($m) => $m->manifest->name, $registry->loaded());

        // Refuse before changing anything when a real file or directory is in a link's way.
        foreach (array_keys($wanted) as $name) {
            $path = "{$base}/{$name}";
            if (file_exists($path) && ! is_link($path)) {
                $this->error("{$path} is a real file or directory, not a link; remove or move it yourself.");

                return self::FAILURE;
            }
        }

        if (! is_dir($base) && ! mkdir($base, 0755) && ! is_dir($base)) {
            $this->error("Cannot create {$base}.");

            return self::FAILURE;
        }

        foreach ($wanted as $name => $target) {
            $path = "{$base}/{$name}";
            if (is_link($path) && readlink($path) === $target) {
                $this->line("ok {$name}");

                continue;
            }
            $temp = "{$base}/.{$name}-".bin2hex(random_bytes(6));
            if (! symlink($target, $temp)) {
                $this->error("Cannot create a link at {$temp}.");

                return self::FAILURE;
            }
            if (! rename($temp, $path)) {
                unlink($temp);
                $this->error("Cannot put the link at {$path}.");

                return self::FAILURE;
            }
            $this->line("linked {$name} -> {$target}");
        }

        $entries = scandir($base);
        foreach ($entries === false ? [] : $entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_link("{$base}/{$entry}") || in_array($entry, $loadedNames, true)) {
                continue;
            }
            if (str_starts_with($entry, '.')) {
                continue;
            }
            unlink("{$base}/{$entry}");
            $this->line("removed {$entry}");
        }

        return self::SUCCESS;
    }
}
