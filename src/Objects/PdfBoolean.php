<?php

declare(strict_types=1);

namespace Typdf\Objects;

class PdfBoolean extends PdfObject
{
    public function __construct(private readonly bool $value)
    {
    }

    public function getValue(): bool
    {
        return $this->value;
    }
}
