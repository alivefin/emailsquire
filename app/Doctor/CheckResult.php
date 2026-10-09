<?php

namespace App\Doctor;

final readonly class CheckResult
{
    public const MAX_ITEMS = 20;

    public string $title;

    public ?string $detail;

    /** @var list<string> */
    public array $items;

    /**
     * Items are capped at MAX_ITEMS plus a "… and N more" line, and every
     * string is forced to valid UTF-8, so hostile guide data can neither
     * flood the output nor break `--json`.
     *
     * @param  array<array-key, string>  $items
     */
    public function __construct(
        public string $id,
        public Status $status,
        string $title,
        ?string $detail = null,
        array $items = [],
    ) {
        $this->title = self::scrub($title);
        $this->detail = $detail === null ? null : self::scrub($detail);

        $items = array_values($items);
        $total = count($items);
        $items = array_map(self::scrub(...), array_slice($items, 0, self::MAX_ITEMS));

        if ($total > self::MAX_ITEMS) {
            $items[] = '… and '.($total - self::MAX_ITEMS).' more';
        }

        $this->items = $items;
    }

    private static function scrub(string $value): string
    {
        return mb_scrub($value, 'UTF-8');
    }
}
