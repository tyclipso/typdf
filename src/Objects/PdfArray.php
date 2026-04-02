<?php

declare(strict_types=1);

namespace Typdf\Objects;

class PdfArray extends PdfObject
{
    /** @var PdfObject[] */
    private array $items = [];

    public function add(PdfObject $item): void
    {
        $this->items[] = $item;
    }

    /** @return PdfObject[] */
    public function getItems(): array
    {
        return $this->items;
    }

    public function get(int $index): ?PdfObject
    {
        return $this->items[$index] ?? null;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
