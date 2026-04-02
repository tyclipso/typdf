<?php

declare(strict_types=1);

namespace Typdf\Objects;

class PdfInteger extends PdfObject
{
    public function __construct(private readonly int $value)
    {
    }

    public function getValue(): int
    {
        return $this->value;
    }
}
