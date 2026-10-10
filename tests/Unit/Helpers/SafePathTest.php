<?php

use EmailSquire\Helpers\SafePath;
use EmailSquire\Helpers\UnsafePath;

it('refuses an unsafe address', function (string $address) {
    expect(fn () => SafePath::splitAddress($address))->toThrow(UnsafePath::class);
})->with([
    'empty' => [''],
    'no @' => ['example.org'],
    'empty local part' => ['@example.org'],
    'empty domain' => ['x@'],
    'dot local part' => ['.@example.org'],
    'dot-dot in local part' => ['a..b@example.org'],
    'domain starting with a dot' => ['a@.hidden'],
    'slash in local part' => ['a/b@example.org'],
    'slash in domain' => ['a@example.org/..'],
    'NUL in local part' => ["a\0@example.org"],
    '101-byte local part' => [str_repeat('a', 101).'@example.org'],
    'dot domain' => ['a@.'],
]);

it('splits at the last @ and keeps the parts as given', function (string $address, array $parts) {
    expect(SafePath::splitAddress($address))->toBe($parts);
})->with([
    'mixed case' => ['Anna@Example.org', ['Anna', 'Example.org']],
    'two @' => ['a@b@example.org', ['a@b', 'example.org']],
    '100-byte local part' => [str_repeat('a', 100).'@example.org', [str_repeat('a', 100), 'example.org']],
]);

describe('walk', function () {
    beforeEach(function () {
        $this->tmp = sys_get_temp_dir().'/emailsquire-safepath-'.bin2hex(random_bytes(6));
        mkdir("{$this->tmp}/root/example.org", 0700, true);
        mkdir("{$this->tmp}/outside/example.org", 0700, true);
    });

    afterEach(function () {
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->tmp, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->tmp);
    });

    it('joins the components and allows a missing last one', function () {
        expect(SafePath::walk("{$this->tmp}/root", 'example.org', 'anna'))->toBe("{$this->tmp}/root/example.org/anna")
            ->and(SafePath::walk("{$this->tmp}/root", 'missing.org', 'anna'))->toBe("{$this->tmp}/root/missing.org/anna");
    });

    it('refuses a symlinked root', function () {
        symlink("{$this->tmp}/outside", "{$this->tmp}/link");

        expect(fn () => SafePath::walk("{$this->tmp}/link", 'example.org'))->toThrow(UnsafePath::class);
    });

    it('refuses a symlinked middle component', function () {
        symlink("{$this->tmp}/outside/example.org", "{$this->tmp}/root/linked.org");

        expect(fn () => SafePath::walk("{$this->tmp}/root", 'linked.org', 'anna'))->toThrow(UnsafePath::class);
    });

    it('refuses a regular file where a directory is expected', function () {
        touch("{$this->tmp}/root/example.org/anna");

        expect(fn () => SafePath::walk("{$this->tmp}/root", 'example.org', 'anna'))->toThrow(UnsafePath::class)
            ->and(fn () => SafePath::walk("{$this->tmp}/root", 'example.org', 'anna', 'cur'))->toThrow(UnsafePath::class);
    });

    it('refuses a component that would leave its parent', function (string $component) {
        expect(fn () => SafePath::walk("{$this->tmp}/root", $component))->toThrow(UnsafePath::class);
    })->with(['', '.', '..', 'a/b', "a\0"]);

    it('refuses a relative root', function () {
        expect(fn () => SafePath::walk('relative/root', 'example.org'))->toThrow(UnsafePath::class);
    });
});
