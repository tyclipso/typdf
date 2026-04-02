<?php

declare(strict_types=1);

namespace Typdf\Objects;

class PdfDictionary extends PdfObject
{
    /** @var array<string, PdfObject> */
    private array $entries = [];

    public function set(string $key, PdfObject $value): void
    {
        $this->entries[$key] = $value;
    }

    public function get(string $key): ?PdfObject
    {
        return $this->entries[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return isset($this->entries[$key]);
    }

    public function remove(string $key): void
    {
        unset($this->entries[$key]);
    }

    /** @return string[] */
    public function getKeys(): array
    {
        return array_keys($this->entries);
    }

    /** @return array<string, PdfObject> */
    public function getEntries(): array
    {
        return $this->entries;
    }
}
