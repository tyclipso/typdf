<?php

declare(strict_types=1);

namespace Typdf\Writer;

use Typdf\Objects\{PdfArray, PdfDictionary, PdfInteger, PdfName, PdfObject, PdfReference, PdfStream};

/**
 * Appends an incremental update section to an existing PDF byte string.
 *
 * Two formats are supported, chosen automatically:
 *
 *  • Traditional xref table  — used when the original file uses traditional
 *    cross-reference tables (non-Flate, older PDFs).
 *
 *  • Cross-reference stream  — REQUIRED when the original file uses
 *    cross-reference streams (PDF 1.5+, modern Adobe Acrobat output).
 *    The PDF spec forbids mixing a traditional table update into a
 *    cross-reference-stream-only file; many readers would silently ignore
 *    the entire original xref stream, leaving only the handful of modified
 *    objects visible.
 */
class IncrementalWriter
{
    private ObjectSerializer $serializer;

    /** @var array<int, array{gen: int, obj: PdfObject}> objNum => {gen, obj} */
    private array $newObjects = [];

    public function __construct(
        private readonly string $originalData,
        private readonly int $originalMaxObjNum,
        private readonly int $originalXrefOffset,
        private readonly PdfDictionary $originalTrailer,
        private readonly bool $usesXrefStreams = false,
    ) {
        $this->serializer = new ObjectSerializer();
    }

    /**
     * Queue an object to be written in the incremental update.
     * Use the existing object number to replace an object, or a new number
     * (> originalMaxObjNum) to add a fresh one.
     */
    public function addObject(int $objNum, int $genNum, PdfObject $obj): void
    {
        $this->newObjects[$objNum] = ['gen' => $genNum, 'obj' => $obj];
    }

    /**
     * Build the full updated PDF bytes (original + incremental update).
     */
    public function build(): string
    {
        if (empty($this->newObjects)) {
            return $this->originalData;
        }

        $base = strlen($this->originalData);

        // Ensure a clean newline separator between the original content and our
        // appended section.  Do NOT add strlen($sep) to $base — $sep is part
        // of the new content and is already accounted for via strlen($objectsContent).
        $sep            = str_ends_with($this->originalData, "\n") ? '' : "\n";
        $objectsContent = $sep;
        $offsets        = [];

        foreach ($this->newObjects as $objNum => $entry) {
            $offsets[$objNum] = $base + strlen($objectsContent);
            $objectsContent  .= $this->serializer->serializeIndirect($objNum, $entry['gen'], $entry['obj']);
            $objectsContent  .= "\n";
        }

        $newSize = max($this->originalMaxObjNum, max(array_keys($this->newObjects))) + 1;

        if ($this->usesXrefStreams) {
            $suffix = $this->buildXrefStreamUpdate($offsets, $newSize, $base + strlen($objectsContent));
        } else {
            $xrefOffset = $base + strlen($objectsContent);
            $suffix     = $this->buildTraditionalXrefUpdate($offsets, $newSize, $xrefOffset);
        }

        return $this->originalData . $objectsContent . $suffix;
    }

    /**
     * Write the updated PDF to a file.
     */
    public function save(string $filePath): void
    {
        $result = $this->build();
        if (file_put_contents($filePath, $result) === false) {
            throw new \RuntimeException("Cannot write to file: {$filePath}");
        }
    }

    // -----------------------------------------------------------------------
    // Traditional xref table + trailer
    // -----------------------------------------------------------------------

    private function buildTraditionalXrefUpdate(
        array $offsets,
        int $newSize,
        int $xrefOffset,
    ): string {
        return $this->buildXrefTable($offsets)
            . $this->buildTraditionalTrailer($newSize, $xrefOffset);
    }

    private function buildXrefTable(array $offsets): string
    {
        ksort($offsets);

        $sections = $this->groupConsecutive(array_keys($offsets));

        $xref = "xref\n";
        foreach ($sections as $group) {
            $xref .= $group[0] . ' ' . count($group) . "\n";
            foreach ($group as $num) {
                $gen   = $this->newObjects[$num]['gen'];
                $xref .= sprintf("%010d %05d n\r\n", $offsets[$num], $gen);
            }
        }

        return $xref;
    }

    private function buildTraditionalTrailer(int $newSize, int $xrefOffset): string
    {
        $dict = new PdfDictionary();
        $dict->set('Size', new PdfInteger($newSize));
        $dict->set('Prev', new PdfInteger($this->originalXrefOffset));

        $root = $this->originalTrailer->get('Root');
        if ($root !== null) {
            $dict->set('Root', $root);
        }
        $info = $this->originalTrailer->get('Info');
        if ($info !== null) {
            $dict->set('Info', $info);
        }

        $ser = new ObjectSerializer();

        return "trailer\n" . $ser->serialize($dict) . "\nstartxref\n{$xrefOffset}\n%%EOF\n";
    }

    // -----------------------------------------------------------------------
    // Cross-reference stream (required for PDF 1.5+ xref-stream files)
    // -----------------------------------------------------------------------

    /**
     * Build a cross-reference stream incremental update section.
     *
     * The xref stream object itself is assigned the next available object
     * number (newSize) and its offset is $xrefStreamOffset, which the caller
     * has already computed as the position immediately after the serialised
     * modified objects.
     */
    private function buildXrefStreamUpdate(
        array $offsets,
        int $newSize,
        int $xrefStreamOffset,
    ): string {
        // The xref stream is itself a new object.
        $xrefObjNum = $newSize;
        $finalSize  = $newSize + 1; // /Size must cover the xref stream object too

        // Entry widths: [1 byte type | 4 bytes offset | 2 bytes generation]
        // 4-byte offset supports files up to 4 GB.
        $w1 = 1;
        $w2 = 4;
        $w3 = 2;

        // Collect all new entries: modified objects + the xref stream itself
        $allEntries = $offsets;
        $allEntries[$xrefObjNum] = $xrefStreamOffset;
        ksort($allEntries);

        $sections = $this->groupConsecutive(array_keys($allEntries));

        $indexArray = new PdfArray();
        $binaryData = '';

        foreach ($sections as $group) {
            $indexArray->add(new PdfInteger($group[0]));
            $indexArray->add(new PdfInteger(count($group)));

            foreach ($group as $num) {
                $offset = $allEntries[$num];
                $gen    = ($num === $xrefObjNum)
                    ? 0
                    : ($this->newObjects[$num]['gen'] ?? 0);

                $binaryData .= chr(1); // type = in-use
                $binaryData .= $this->packBigEndian($offset, $w2);
                $binaryData .= $this->packBigEndian($gen, $w3);
            }
        }

        // Build the stream dictionary
        $wArray = new PdfArray();
        $wArray->add(new PdfInteger($w1));
        $wArray->add(new PdfInteger($w2));
        $wArray->add(new PdfInteger($w3));

        $dict = new PdfDictionary();
        $dict->set('Type',   new PdfName('XRef'));
        $dict->set('Size',   new PdfInteger($finalSize));
        $dict->set('W',      $wArray);
        $dict->set('Index',  $indexArray);
        $dict->set('Prev',   new PdfInteger($this->originalXrefOffset));
        $dict->set('Length', new PdfInteger(strlen($binaryData)));

        $root = $this->originalTrailer->get('Root');
        if ($root !== null) {
            $dict->set('Root', $root);
        }
        $info = $this->originalTrailer->get('Info');
        if ($info !== null) {
            $dict->set('Info', $info);
        }

        // Serialise the xref stream as a raw (uncompressed) stream object
        $ser    = new ObjectSerializer();
        $stream = new PdfStream($dict, $binaryData);
        $objStr = "{$xrefObjNum} 0 obj\n"
            . $ser->serialize($stream)
            . "\nendobj\n";

        return $objStr . "startxref\n{$xrefStreamOffset}\n%%EOF\n";
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    /**
     * Group a sorted list of integers into runs of consecutive values.
     *
     * @param  int[] $nums  Sorted object numbers.
     * @return int[][]
     */
    private function groupConsecutive(array $nums): array
    {
        $groups  = [];
        $current = [];

        foreach ($nums as $num) {
            if (empty($current) || $num === end($current) + 1) {
                $current[] = $num;
            } else {
                $groups[]  = $current;
                $current   = [$num];
            }
        }

        if (!empty($current)) {
            $groups[] = $current;
        }

        return $groups;
    }

    /** Encode an integer as a big-endian byte string of the given width. */
    private function packBigEndian(int $value, int $width): string
    {
        $bytes = '';
        for ($i = $width - 1; $i >= 0; $i--) {
            $bytes = chr($value & 0xFF) . $bytes;
            $value >>= 8;
        }

        return $bytes;
    }
}
