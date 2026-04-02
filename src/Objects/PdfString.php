<?php

declare(strict_types=1);

namespace Typdf\Objects;

/**
 * A PDF string value. Stores raw bytes as parsed from the PDF.
 * Can be PDFDocEncoding (like Latin-1) or UTF-16BE with BOM.
 */
class PdfString extends PdfObject
{
    public function __construct(private readonly string $rawBytes)
    {
    }

    public function getRawBytes(): string
    {
        return $this->rawBytes;
    }

    /**
     * Decode raw PDF string bytes to a PHP UTF-8 string.
     * Detects UTF-16BE BOM; otherwise treats as PDFDocEncoding (Latin-1).
     */
    public function getValue(): string
    {
        if (str_starts_with($this->rawBytes, "\xFE\xFF")) {
            return mb_convert_encoding(substr($this->rawBytes, 2), 'UTF-8', 'UTF-16BE');
        }

        return mb_convert_encoding($this->rawBytes, 'UTF-8', 'ISO-8859-1');
    }

    /**
     * Create a PdfString from a PHP UTF-8 string.
     * Uses PDFDocEncoding (Latin-1) when possible, otherwise UTF-16BE with BOM.
     */
    public static function fromValue(string $utf8Value): self
    {
        $latin1 = mb_convert_encoding($utf8Value, 'ISO-8859-1', 'UTF-8');
        if (mb_convert_encoding($latin1, 'UTF-8', 'ISO-8859-1') === $utf8Value) {
            return new self($latin1);
        }

        return new self("\xFE\xFF" . mb_convert_encoding($utf8Value, 'UTF-16BE', 'UTF-8'));
    }
}
