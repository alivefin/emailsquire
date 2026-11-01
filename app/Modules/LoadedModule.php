<?php

namespace App\Modules;

/** A module the loader accepted: its manifest and its registered provider. */
final readonly class LoadedModule
{
    public function __construct(
        public ModuleManifest $manifest,
        public Module $provider,
    ) {}
}
