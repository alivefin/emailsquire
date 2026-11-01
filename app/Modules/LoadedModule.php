<?php

namespace App\Modules;

use Closure;
use Illuminate\Support\Facades\Log;
use Throwable;

/** A module the loader accepted: its manifest and its registered provider. */
final readonly class LoadedModule
{
    public function __construct(
        public ModuleManifest $manifest,
        public Module $provider,
    ) {}

    /**
     * Runs $call, one of the core's calls into the module after loading. A throw is logged with the
     * module's name and the exception's class and message (no trace or context), and $fallback is returned
     * instead, so a broken module costs its own contribution and nothing else.
     *
     * @template T
     *
     * @param  string  $what  what is called, for the log, e.g. `navigation()`
     * @param  Closure(): T  $call
     * @param  T  $fallback
     * @return T
     */
    public function guard(string $what, Closure $call, mixed $fallback): mixed
    {
        try {
            return $call();
        } catch (Throwable $e) {
            $this->warn("{$what} failed: ".$e::class.': '.$e->getMessage());

            return $fallback;
        }
    }

    /** Logs a warning about this module; the core skipped part of what it offered. */
    public function warn(string $message): void
    {
        Log::warning("Module {$this->manifest->name}: {$message}");
    }
}
