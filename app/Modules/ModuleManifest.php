<?php

namespace App\Modules;

use JsonException;

/** A validated `module.json`. Paths are checked for shape only; the loader checks they exist. */
final readonly class ModuleManifest
{
    public function __construct(
        public string $name,
        public string $version,
        public string $coreApi,
        public string $namespace,
        public string $provider,
        public string $autoload,
        public string $tablePrefix,
        public string $route,
        public ?string $entry,
        public ?string $style,
        public string $dir,
    ) {}

    /** @throws ManifestInvalid */
    public static function fromFile(string $moduleDir): self
    {
        $file = rtrim($moduleDir, '/').'/module.json';
        if (! is_file($file)) {
            throw new ManifestInvalid('manifest_missing', 'module.json not found.');
        }

        $json = file_get_contents($file);
        if ($json === false) {
            throw new ManifestInvalid('manifest_invalid', 'module.json cannot be read.');
        }

        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ManifestInvalid('manifest_invalid', 'module.json is not valid JSON: '.$e->getMessage());
        }
        if (! is_array($data) || array_is_list($data)) {
            throw new ManifestInvalid('manifest_invalid', 'module.json must be a JSON object.');
        }

        $name = self::string($data, 'name', '/^[a-z][a-z0-9-]{0,39}$/');
        $prefix = self::string($data, 'table_prefix', '/^[a-z][a-z0-9_]{0,19}_$/');
        $route = self::string($data, 'route', '/^\/[a-z][a-z0-9-]{0,39}$/');
        $namespace = self::string($data, 'namespace');
        $provider = self::string($data, 'provider');
        $coreApi = self::string($data, 'core_api');
        $version = self::string($data, 'version');
        $autoload = self::relative(self::string($data, 'autoload'), 'autoload');

        if (! str_ends_with($namespace, '\\') || str_starts_with($namespace, 'App\\')) {
            throw new ManifestInvalid('manifest_invalid', 'namespace must end with "\\" and must not start with "App\\".');
        }

        $assets = $data['assets'] ?? [];
        if (! is_array($assets)) {
            throw new ManifestInvalid('manifest_invalid', 'assets must be an object.');
        }

        return new self(
            name: $name,
            version: $version,
            coreApi: $coreApi,
            namespace: $namespace,
            provider: $provider,
            autoload: rtrim($moduleDir, '/').'/'.$autoload,
            tablePrefix: $prefix,
            route: $route,
            entry: self::asset($assets, 'entry'),
            style: self::asset($assets, 'style'),
            dir: $moduleDir,
        );
    }

    /** @param  array<mixed>  $data */
    private static function string(array $data, string $key, ?string $pattern = null): string
    {
        $value = $data[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new ManifestInvalid('manifest_invalid', "{$key} is required and must be a non-empty string.");
        }
        if ($pattern !== null && preg_match($pattern, $value) !== 1) {
            throw new ManifestInvalid('manifest_invalid', "{$key} does not match {$pattern}.");
        }

        return $value;
    }

    /** A relative path with no `..` or empty segments; returned normalised. */
    private static function relative(string $path, string $key): string
    {
        $segments = explode('/', $path);
        if (str_starts_with($path, '/') || str_contains($path, "\0") || str_contains($path, '\\')
            || array_intersect($segments, ['', '.', '..']) !== []) {
            throw new ManifestInvalid('manifest_invalid', "{$key} must be a relative path inside the module directory.");
        }

        return $path;
    }

    /**
     * @param  array<mixed>  $assets
     * @return ?string path relative to the module's `public/` directory
     */
    private static function asset(array $assets, string $key): ?string
    {
        if (! array_key_exists($key, $assets)) {
            return null;
        }
        $value = $assets[$key];
        if (! is_string($value) || $value === '') {
            throw new ManifestInvalid('manifest_invalid', "assets.{$key} must be a non-empty string.");
        }

        $path = self::relative($value, "assets.{$key}");
        if (! str_starts_with($path, 'public/') || strlen($path) === strlen('public/')) {
            throw new ManifestInvalid('manifest_invalid', "assets.{$key} must be a file under public/.");
        }

        return substr($path, strlen('public/'));
    }
}
