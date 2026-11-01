<?php

namespace App\Modules;

use Illuminate\Support\ServiceProvider;
use Throwable;

/**
 * Loads the modules an operator listed (`emailsquire.modules`), and only those: each must be a directory
 * below `emailsquire.modules_root` whose directories root alone can change, with a valid manifest and a
 * provider that implements Module. Anything else is refused with a stable code and skipped; loading never
 * throws, so a broken module cannot stop the console from booting. A loaded module is fully trusted (it
 * runs in this process), which is why the checks come before any of its code does.
 */
final class ModuleLoader
{
    /**
     * Registers each accepted module's provider with the application. A relative entry, like a relative
     * modules root, is taken from the app's base directory.
     *
     * @param  array<mixed>  $paths
     */
    public static function load(array $paths): ModuleRegistry
    {
        $root = realpath(self::absolute(config()->string('emailsquire.modules_root')));
        $checkOwner = self::ownershipEnforced();

        $loaded = [];
        $refused = [];
        foreach ($paths as $path) {
            $result = self::loadOne(is_string($path) ? $path : '', $root === false ? null : $root, $checkOwner, $loaded);
            if ($result instanceof LoadedModule) {
                $loaded[] = $result;
            } else {
                $refused[] = $result;
            }
        }

        return new ModuleRegistry($loaded, $refused);
    }

    /**
     * Root ownership may be waived only in testing, e2e and local, where module files belong to the
     * developer. Anywhere else the setting is ignored (and the doctor fails it).
     */
    public static function ownershipEnforced(): bool
    {
        return config()->boolean('emailsquire.modules_require_root_owner')
            || ! app()->environment(['testing', 'e2e', 'local']);
    }

    /** @param  list<LoadedModule>  $loaded  the modules accepted so far */
    private static function loadOne(string $listed, ?string $root, bool $checkOwner, array $loaded): LoadedModule|ModuleRefusal
    {
        $refuse = fn (string $reason, string $detail, ?string $name = null) => new ModuleRefusal($listed, $name, $reason, $detail);

        $path = self::absolute($listed);
        if ($listed === '' || str_contains($listed, "\0") || ! is_dir($path)) {
            return $refuse('not_directory', "{$path} is not a directory.");
        }

        $dir = realpath($path);
        if ($dir === false || $root === null || ! self::inside($dir, $root)) {
            $where = $root ?? config()->string('emailsquire.modules_root').' (missing)';

            return $refuse('outside_root', "{$path} resolves to ".($dir ?: '?').", which is not below the modules root {$where}.");
        }

        if ($checkOwner && ! PathOwner::isRootOwnedUpTo($dir, $root)) {
            return $refuse('not_root_owned', "{$dir}, or a directory above it up to {$root}, is not owned by root or is writable by its group or others.");
        }

        try {
            $manifest = ModuleManifest::fromFile($dir);
        } catch (ManifestInvalid $e) {
            return $refuse($e->reason, $e->getMessage());
        }
        $name = $manifest->name;

        if (! CoreApi::satisfies($manifest->coreApi)) {
            return $refuse('core_api_unsupported', "core_api {$manifest->coreApi} does not accept core API ".CoreApi::VERSION.'.', $name);
        }

        foreach ($loaded as $other) {
            if ($other->manifest->name === $name) {
                return $refuse('duplicate_name', "A module named {$name} is already loaded from {$other->manifest->dir}.", $name);
            }
        }
        foreach ($loaded as $other) {
            if ($other->manifest->route === $manifest->route) {
                return $refuse('route_clash', "Route {$manifest->route} is already claimed by module {$other->manifest->name}.", $name);
            }
        }

        // The manifest's paths are checked for shape only; here they must also resolve inside the module,
        // so a link cannot make the core run, or serve, files from anywhere else.
        $autoload = realpath($manifest->autoload);
        if ($autoload === false || ! is_file($autoload)) {
            return $refuse('autoload_missing', "{$manifest->autoload} does not exist.", $name);
        }
        if (! self::inside($autoload, $dir)) {
            return $refuse('outside_root', "{$manifest->autoload} resolves to {$autoload}, outside the module.", $name);
        }
        foreach ([$manifest->entry, $manifest->style] as $asset) {
            $file = $asset === null ? false : realpath("{$dir}/public/{$asset}");
            if ($file !== false && ! self::inside($file, $dir)) {
                return $refuse('outside_root', "public/{$asset} resolves to {$file}, outside the module.", $name);
            }
        }

        $provider = $manifest->provider;
        try {
            self::requireFile($autoload);
            if (! class_exists($provider)) {
                return $refuse('provider_missing', "Provider class {$provider} was not found.", $name);
            }
        } catch (Throwable $e) {
            return $refuse('provider_failed', 'Loading the provider failed: '.$e::class.': '.$e->getMessage(), $name);
        }
        if (! is_subclass_of($provider, ServiceProvider::class) || ! is_subclass_of($provider, Module::class)) {
            return $refuse('provider_not_module', "{$provider} must extend ".ServiceProvider::class.' and implement '.Module::class.'.', $name);
        }

        // A throw from the provider's boot() is not caught: boot runs with the application's, after this.
        try {
            $instance = app()->register($provider);
        } catch (Throwable $e) {
            return $refuse('provider_failed', 'Registering the provider failed: '.$e::class.': '.$e->getMessage(), $name);
        }
        if (! $instance instanceof Module) {
            return $refuse('provider_not_module', "{$provider} must implement ".Module::class.'.', $name);
        }

        return new LoadedModule($manifest, $instance);
    }

    /** Requires $file in a scope of its own, so it sees none of the loader's variables. */
    private static function requireFile(string $file): void
    {
        (static function (string $__file): void {
            require_once $__file;
        })($file);
    }

    private static function inside(string $path, string $dir): bool
    {
        return str_starts_with($path, rtrim($dir, '/').'/');
    }

    private static function absolute(string $path): string
    {
        return str_starts_with($path, '/') ? $path : base_path($path);
    }
}
