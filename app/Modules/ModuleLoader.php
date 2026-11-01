<?php

namespace App\Modules;

use App\MailServer\AuditActions;
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
        return config()->boolean('emailsquire.modules_require_root_owner') || ! self::ownershipMayBeOff();
    }

    /** Whether this environment may turn the ownership check off. */
    public static function ownershipMayBeOff(): bool
    {
        return app()->environment(['testing', 'e2e', 'local']);
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

        // No link may lead out of the module, so nothing below checks, runs or serves a file elsewhere.
        $link = PathOwner::firstEscapingLink($dir);
        if ($link !== null) {
            return $refuse('outside_root', "{$link} is a link that does not resolve inside {$dir}, or cannot be read.");
        }

        // The module's code runs in this process, as root too (scripts/root-artisan): anyone who can
        // change a file of it, or a directory above it, could run code as root.
        if ($checkOwner) {
            $unsafe = PathOwner::firstNotRootOwned($dir);
            if ($unsafe !== null) {
                return $refuse('not_root_owned', "{$unsafe} is not owned by root, is writable by its group or others, or cannot be read.");
            }
            if (! PathOwner::isRootOwnedUpTo($dir, $root)) {
                return $refuse('not_root_owned', "{$dir}, or a directory above it, is not owned by root or is writable by its group or others.");
            }
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

        // The manifest's paths are checked for shape only, and the links in the module were checked above;
        // the autoloader is checked once more where it is used.
        $autoload = realpath($manifest->autoload);
        if ($autoload === false || ! is_file($autoload)) {
            return $refuse('autoload_missing', "{$manifest->autoload} does not exist.", $name);
        }
        if (! self::inside($autoload, $dir)) {
            return $refuse('outside_root', "{$manifest->autoload} resolves to {$autoload}, outside the module.", $name);
        }

        // One module, one provider of its own: a class outside the module's namespace, or one another module
        // has registered already, would be some other module's code.
        $provider = $manifest->provider;
        if (! str_starts_with($provider, $manifest->namespace)) {
            return $refuse('provider_not_module', "{$provider} is not in the module's namespace {$manifest->namespace}.", $name);
        }
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

        if (app()->getProviders($provider) !== []) {
            return $refuse('provider_not_module', "{$provider} is already registered by another module or the core.", $name);
        }

        // The provider is built, not registered, so a module that clashes never runs any of its register().
        // The instance that was checked is the one registered.
        try {
            $instance = app()->resolveProvider($provider);
        } catch (Throwable $e) {
            return $refuse('provider_failed', 'Building the provider failed: '.$e::class.': '.$e->getMessage(), $name);
        }
        if (! $instance instanceof Module) {
            return $refuse('provider_not_module', "{$provider} must implement ".Module::class.'.', $name);
        }
        $clash = self::auditClash($instance, $loaded);
        if ($clash !== null) {
            return $refuse($clash[0], $clash[1], $name);
        }

        // A throw from the provider's boot() is not caught: boot runs with the application's, after this.
        try {
            $registered = app()->register($instance);
        } catch (Throwable $e) {
            return $refuse('provider_failed', 'Registering the provider failed: '.$e::class.': '.$e->getMessage(), $name);
        }
        if (! $registered instanceof Module) {
            return $refuse('provider_not_module', "{$provider} must implement ".Module::class.'.', $name);
        }

        return new LoadedModule($manifest, $registered);
    }

    /**
     * What is wrong with the audit actions the provider declares, as [reason, detail], or null. A module's
     * actions must all start with its own prefix, which is none of the core's; no group name or action may
     * already belong to the core or to a module loaded before it. Reading them runs nothing of the module's
     * but these two methods.
     *
     * @param  list<LoadedModule>  $loaded
     * @return array{0: string, 1: string}|null
     */
    private static function auditClash(Module $instance, array $loaded): ?array
    {
        try {
            $prefix = $instance->auditPrefix();
            $declared = $instance->auditGroups();
        } catch (Throwable $e) {
            return ['provider_failed', 'Reading the audit actions failed: '.$e::class.': '.$e->getMessage()];
        }

        if (preg_match('/^[a-z][a-z0-9_-]*\.$/', $prefix) !== 1) {
            return ['audit_clash', "Audit prefix '{$prefix}' must be lowercase words ending in a dot, like 'example.'."];
        }
        foreach (AuditActions::corePrefixes() as $core) {
            if (str_starts_with($prefix, $core)) {
                return ['audit_clash', "Audit prefix '{$prefix}' is within the core's {$core}"];
            }
        }

        $groups = AuditActions::GROUPS;
        $actions = array_merge(...array_values($groups));
        foreach ($loaded as $other) {
            $groups += $other->provider->auditGroups();
            array_push($actions, ...array_merge([], ...array_values($other->provider->auditGroups())));
            if ($other->provider->auditPrefix() === $prefix) {
                return ['audit_clash', "Audit prefix '{$prefix}' is already used by module {$other->manifest->name}."];
            }
        }

        foreach ($declared as $group => $names) {
            if (isset($groups[$group])) {
                return ['audit_clash', "Audit group '{$group}' already exists."];
            }
            foreach ($names as $action) {
                if (! str_starts_with($action, $prefix)) {
                    return ['audit_clash', "Audit action '{$action}' does not start with the module's prefix '{$prefix}'."];
                }
                if (in_array($action, $actions, true)) {
                    return ['audit_clash', "Audit action '{$action}' already exists."];
                }
                $actions[] = $action;
            }
        }

        return null;
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
