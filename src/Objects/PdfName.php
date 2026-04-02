<?php

declare(strict_types=1);

namespace Typdf\Objects;

/**
 * A PDF name object (e.g. /Helvetica).
 * The value is stored without the leading slash, with #xx escapes resolved.
 */
class PdfName extends PdfObject
{
    public function __construct(private readonly string $value)
    {
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
