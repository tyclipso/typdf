<?php

declare(strict_types=1);

namespace Typdf\Objects;

class PdfReal extends PdfObject
{
    public function __construct(private readonly float $value)
    {
    }

    public function getValue(): float
    {
        return $this->value;
    }
}
