<?php

namespace App\Modules;

/** One console navigation item of a module: `labelKey` is an i18n key in the module's catalogues. */
final readonly class NavItem
{
    public function __construct(
        public string $key,
        public string $path,
        public string $labelKey,
    ) {}

    /** @return array{key: string, path: string, label_key: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'path' => $this->path, 'label_key' => $this->labelKey];
    }
}
