<?php

namespace App\Modules;

/**
 * A listed module the loader skipped. `path` is the entry as listed in `EMAILSQUIRE_MODULES`; `name` is
 * known once the manifest has been read; `reason` is a stable snake_case code; `detail` says why in English.
 */
final readonly class ModuleRefusal
{
    public function __construct(
        public string $path,
        public ?string $name,
        public string $reason,
        public string $detail,
    ) {}
}
