<?php

declare(strict_types=1);

namespace Typdf\Objects;

/**
 * An indirect reference to another PDF object (e.g. "7 0 R").
 */
class PdfReference extends PdfObject
{
    public function __construct(
        private readonly int $objectNumber,
        private readonly int $generationNumber,
    ) {
    }

    public function getObjectNumber(): int
    {
        return $this->objectNumber;
    }

    public function getGenerationNumber(): int
    {
        return $this->generationNumber;
    }
}
