<?php

use EmailSquire\Helpers\Dkim\SelectorMap;
use Tests\Support\TempTree;

beforeEach(function () {
    $this->tmp = realpath(TempTree::create());
    $this->map = "{$this->tmp}/dkim_selectors.map";
    file_put_contents($this->map, "example.org old\n");
});

afterEach(fn () => TempTree::remove($this->tmp));

it('removes its temp file and keeps the map when rename() fails', function () {
    $temps = [];
    $map = new SelectorMap(
        $this->map,
        function (string $temp) use (&$temps): void {
            $temps[] = $temp;
        },
        fn (string $from, string $to): bool => false,
    );

    expect(fn () => $map->activate('example.org', 'es20261011'))->toThrow(RuntimeException::class, 'cannot replace the selector map');

    expect($temps)->toHaveCount(1)
        ->and(dirname($temps[0]))->toBe($this->tmp)
        ->and($temps[0])->not->toBeFile()
        ->and(scandir($this->tmp))->toBe(['.', '..', 'dkim_selectors.map'])
        ->and(file_get_contents($this->map))->toBe("example.org old\n");
});

it('removes its temp file and keeps the other writer\'s map when the map changed after it was read', function (string $change) {
    $map = new SelectorMap($this->map, function (string $temp) use ($change): void {
        expect(file_get_contents($temp))->toBe("example.org es20261011\n");
        match ($change) {
            'rewritten' => file_put_contents($this->map, "example.org edited-by-hand\n"),
            'created' => null,
        };
    });
    if ($change === 'created') {
        unlink($this->map);
        $map = new SelectorMap($this->map, function (): void {
            file_put_contents($this->map, "example.org edited-by-hand\n");
        });
    }

    expect(fn () => $map->activate('example.org', 'es20261011'))->toThrow(RuntimeException::class, 'the selector map changed while it was rewritten');

    expect(scandir($this->tmp))->toBe(['.', '..', 'dkim_selectors.map'])
        ->and(file_get_contents($this->map))->not->toContain('es20261011');
})->with(['rewritten', 'created']);
