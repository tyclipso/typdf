<?php

declare(strict_types=1);

namespace Typdf\Writer;

use Typdf\Exception\PdfException;
use Typdf\Objects\{
    PdfArray,
    PdfBoolean,
    PdfDictionary,
    PdfInteger,
    PdfName,
    PdfNull,
    PdfObject,
    PdfReal,
    PdfReference,
    PdfStream,
    PdfString,
};

/**
 * Serialises PdfObject instances back to their PDF byte representation.
 */
class ObjectSerializer
{
    /**
     * Serialise any PdfObject to its PDF byte string.
     */
    public function serialize(PdfObject $obj): string
    {
        return match (true) {
            $obj instanceof PdfNull       => 'null',
            $obj instanceof PdfBoolean    => $obj->getValue() ? 'true' : 'false',
            $obj instanceof PdfInteger    => (string) $obj->getValue(),
            $obj instanceof PdfReal       => $this->serializeReal($obj->getValue()),
            $obj instanceof PdfName       => '/' . $this->escapeName($obj->getValue()),
            $obj instanceof PdfString     => $this->serializeString($obj->getRawBytes()),
            $obj instanceof PdfReference  => $obj->getObjectNumber() . ' ' . $obj->getGenerationNumber() . ' R',
            $obj instanceof PdfArray      => $this->serializeArray($obj),
            $obj instanceof PdfStream     => $this->serializeStream($obj),
            $obj instanceof PdfDictionary => $this->serializeDictionary($obj),
            default                       => throw new PdfException('Unknown PdfObject type: ' . get_class($obj)),
        };
    }

    /**
     * Wrap the serialised object in an indirect-object definition:
     *   N G obj\n<body>\nendobj\n
     */
    public function serializeIndirect(int $objNum, int $genNum, PdfObject $obj): string
    {
        return "{$objNum} {$genNum} obj\n" . $this->serialize($obj) . "\nendobj\n";
    }

    // -----------------------------------------------------------------------

    private function serializeReal(float $value): string
    {
        // Use enough precision; strip unnecessary trailing zeros.
        $str = rtrim(number_format($value, 6, '.', ''), '0');

        return rtrim($str, '.') ?: '0';
    }

    private function serializeString(string $rawBytes): string
    {
        // Decide between literal string and hex string.
        // Hex is always safe; we use it for non-ASCII-printable content.
        $needsHex = false;
        for ($i = 0, $l = strlen($rawBytes); $i < $l; $i++) {
            $b = ord($rawBytes[$i]);
            // Allow printable ASCII, tab, newline, carriage return, form feed
            if ($b < 0x20 && !in_array($b, [0x09, 0x0A, 0x0C, 0x0D], true)) {
                $needsHex = true;
                break;
            }
        }

        if ($needsHex || str_starts_with($rawBytes, "\xFE\xFF")) {
            // Hex string
            return '<' . bin2hex($rawBytes) . '>';
        }

        // Literal string with escaping
        $escaped = '';
        for ($i = 0, $l = strlen($rawBytes); $i < $l; $i++) {
            $ch = $rawBytes[$i];
            $escaped .= match ($ch) {
                '('  => '\\(',
                ')'  => '\\)',
                '\\' => '\\\\',
                "\r" => '\\r',
                "\n" => '\\n',
                "\t" => '\\t',
                default => $ch,
            };
        }

        return "({$escaped})";
    }

    private function escapeName(string $name): string
    {
        // Escape characters outside the regular-character range (33–126, excluding delimiters)
        $delimiters = ['(', ')', '<', '>', '[', ']', '{', '}', '/', '%'];
        $result     = '';

        for ($i = 0, $l = strlen($name); $i < $l; $i++) {
            $ch = $name[$i];
            $b  = ord($ch);
            if ($b < 0x21 || $b > 0x7E || in_array($ch, $delimiters, true) || $ch === '#') {
                $result .= '#' . sprintf('%02X', $b);
            } else {
                $result .= $ch;
            }
        }

        return $result;
    }

    private function serializeArray(PdfArray $array): string
    {
        $items = array_map(fn(PdfObject $o) => $this->serialize($o), $array->getItems());

        return '[' . implode(' ', $items) . ']';
    }

    private function serializeDictionary(PdfDictionary $dict): string
    {
        $entries = '';
        foreach ($dict->getEntries() as $key => $value) {
            $entries .= "\n/" . $this->escapeName($key) . ' ' . $this->serialize($value);
        }

        return '<<' . $entries . "\n>>";
    }

    private function serializeStream(PdfStream $stream): string
    {
        $raw  = $stream->getRawData();
        $dict = clone $stream->getDictionary();
        // Ensure /Length matches the raw data
        $dict->set('Length', new PdfInteger(strlen($raw)));

        return $this->serializeDictionary($dict) . "\nstream\n" . $raw . "\nendstream";
    }
}
