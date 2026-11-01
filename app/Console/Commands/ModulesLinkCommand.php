<?php

namespace App\Console\Commands;

use App\Modules\ModuleRegistry;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Links each loaded module's public/ directory to public/modules/<name>. Runs as root, so it only ever
 * touches direct children of public/modules, replaces links atomically (new link, then rename over the old),
 * and never deletes or replaces anything that is not a symlink. Exits 1 when a module was refused or
 * anything could not be done, after doing what it safely can.
 */
final class ModulesLinkCommand extends Command
{
    /** The temporary link names this command makes: .<module name>-<12 hex digits>. */
    private const TEMP_LINK = '/^\.[a-z][a-z0-9-]*-[0-9a-f]{12}$/';

    protected $signature = 'emailsquire:modules:link {--public= : The public directory (default: the application\'s)}';

    protected $description = 'Link loaded modules\' assets into public/modules and remove links of modules that are not loaded';

    public function handle(ModuleRegistry $registry): int
    {
        $option = $this->option('public');
        $public = rtrim(is_string($option) && $option !== '' ? $option : public_path(), '/');
        $base = $public.'/modules';

        try {
            $this->link($registry, $base);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        foreach ($registry->refused() as $refusal) {
            $this->error("{$refusal->path}: {$refusal->reason}");
        }

        return $registry->refused() === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @throws RuntimeException with a one-line message when something cannot be done */
    private function link(ModuleRegistry $registry, string $base): void
    {
        if (is_link($base) || (file_exists($base) && ! is_dir($base))) {
            throw new RuntimeException("{$base} exists and is not a plain directory; refusing to use it.");
        }

        $wanted = [];
        $loadedNames = [];
        foreach ($registry->loaded() as $module) {
            $name = $module->manifest->name;
            $loadedNames[] = $name;
            $target = $module->manifest->dir.'/public';
            if (! is_dir($target)) {
                $this->line("skipped {$name}: no public directory");

                continue;
            }
            $wanted[$name] = $target;
        }

        // Refuse before changing anything when a real file or directory is in a link's way.
        foreach (array_keys($wanted) as $name) {
            $path = "{$base}/{$name}";
            if (file_exists($path) && ! is_link($path)) {
                throw new RuntimeException("{$path} is a real file or directory, not a link; remove or move it yourself.");
            }
        }

        $this->attempt("create {$base}", function () use ($base): void {
            if (! is_dir($base) && ! mkdir($base, 0755) && ! is_dir($base)) {
                throw new RuntimeException('mkdir failed');
            }
        });

        foreach ($wanted as $name => $target) {
            $path = "{$base}/{$name}";
            if (is_link($path) && readlink($path) === $target) {
                $this->line("ok {$name}");

                continue;
            }
            $temp = "{$base}/.{$name}-".bin2hex(random_bytes(6));
            try {
                $this->attempt("link {$path}", function () use ($target, $temp, $path): void {
                    if (! symlink($target, $temp) || ! rename($temp, $path)) {
                        throw new RuntimeException('symlink or rename failed');
                    }
                });
            } finally {
                if (is_link($temp)) {
                    unlink($temp);
                }
            }
            $this->line("linked {$name} -> {$target}");
        }

        $entries = $this->attempt("read {$base}", fn (): array => scandir($base) ?: []);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || ! is_link("{$base}/{$entry}") || in_array($entry, $loadedNames, true)) {
                continue;
            }
            // Dot-prefixed names are removed only when they are this command's own leftover temp links.
            if (str_starts_with($entry, '.') && preg_match(self::TEMP_LINK, $entry) !== 1) {
                continue;
            }
            $this->attempt("remove {$base}/{$entry}", fn () => unlink("{$base}/{$entry}"));
            $this->line("removed {$entry}");
        }
    }

    /**
     * Runs $do; any failure (PHP warnings are exceptions here) becomes a one-line RuntimeException.
     *
     * @template T
     *
     * @param  callable(): T  $do
     * @return T
     */
    private function attempt(string $what, callable $do): mixed
    {
        try {
            return $do();
        } catch (Throwable $e) {
            throw new RuntimeException("Cannot {$what}: ".$e->getMessage(), 0, $e);
        }
    }
}
