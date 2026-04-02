<?php

declare(strict_types=1);

namespace Typdf\CrossReference;

class CrossReference
{
    /** @var array<int, XrefEntry> Object number => entry */
    private array $entries = [];

    /**
     * Add an entry. Entries from newer revisions override older ones, so the
     * first call for a given object number wins (caller must process newest first).
     */
    public function addEntry(int $objectNumber, XrefEntry $entry): void
    {
        if (!isset($this->entries[$objectNumber])) {
            $this->entries[$objectNumber] = $entry;
        }
    }

    public function getEntry(int $objectNumber): ?XrefEntry
    {
        return $this->entries[$objectNumber] ?? null;
    }

    public function getMaxObjectNumber(): int
    {
        if (empty($this->entries)) {
            return 0;
        }

        return max(array_keys($this->entries));
    }
}
