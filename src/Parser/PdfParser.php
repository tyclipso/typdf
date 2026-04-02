<?php

declare(strict_types=1);

namespace Typdf\Parser;

use Typdf\CrossReference\{CrossReference, XrefEntry};
use Typdf\Exception\{EncryptedPdfException, ParseException};
use Typdf\Objects\{
    PdfArray,
    PdfDictionary,
    PdfInteger,
    PdfName,
    PdfObject,
    PdfReference,
    PdfStream,
};

/**
 * File-level PDF parser.
 *
 * Responsibilities:
 *  - Read and validate the PDF header
 *  - Parse the cross-reference table / cross-reference stream (including chains
 *    from linearised or incrementally-updated files)
 *  - Resolve indirect objects, including objects inside object streams (PDF 1.5+)
 *  - Provide getObject() for higher-level code
 */
class PdfParser
{
    private string $data;
    private CrossReference $xref;
    private PdfDictionary $trailer;

    /** @var array<int, PdfObject> Parsed-object cache */
    private array $cache = [];

    /** @var array<int, array<int, PdfObject>> Object-stream cache [streamObjNum][index] */
    private array $objStreamCache = [];

    public function __construct(string $filePath)
    {
        $data = @file_get_contents($filePath);
        if ($data === false) {
            throw new ParseException("Cannot read file: {$filePath}");
        }

        $this->data = $data;
        $this->parse();
    }

    private function parse(): void
    {
        if (!str_starts_with($this->data, '%PDF-')) {
            throw new ParseException('Not a valid PDF file (missing %PDF- header)');
        }

        $this->xref    = new CrossReference();
        $xrefOffset    = $this->findStartXref();
        $this->trailer = $this->parseXrefChain($xrefOffset);

        if ($this->trailer->has('Encrypt')) {
            throw new EncryptedPdfException();
        }
    }

    // -----------------------------------------------------------------------
    // Public API
    // -----------------------------------------------------------------------

    public function getRawData(): string
    {
        return $this->data;
    }

    public function getTrailer(): PdfDictionary
    {
        return $this->trailer;
    }

    /**
     * Returns true when the original file uses cross-reference streams (PDF 1.5+).
     *
     * In that case any incremental update MUST also use a cross-reference stream.
     * Mixing in a traditional xref table causes conforming readers to ignore the
     * original xref stream entirely, making most of the document invisible.
     */
    public function usesXrefStreams(): bool
    {
        $type = $this->trailer->get('Type');

        return $type instanceof PdfName && $type->getValue() === 'XRef';
    }

    /**
     * Resolve a PdfObject: if it is a PdfReference, return the referenced object;
     * otherwise return the object itself.
     */
    public function resolve(PdfObject $obj): PdfObject
    {
        if ($obj instanceof PdfReference) {
            return $this->getObject($obj->getObjectNumber());
        }

        return $obj;
    }

    /**
     * Look up and return the indirect object with the given number.
     *
     * @throws ParseException if the object cannot be found or parsed.
     */
    public function getObject(int $objNum): PdfObject
    {
        if (isset($this->cache[$objNum])) {
            return $this->cache[$objNum];
        }

        $entry = $this->xref->getEntry($objNum);
        if ($entry === null) {
            throw new ParseException("Object {$objNum} not found in cross-reference table");
        }

        if ($entry->type === XrefEntry::TYPE_FREE) {
            throw new ParseException("Object {$objNum} is marked as free");
        }

        if ($entry->type === XrefEntry::TYPE_COMPRESSED) {
            $obj = $this->getCompressedObject($entry->offset, $entry->generation);
        } else {
            $obj = $this->parseObjectAt($entry->offset, $objNum);
        }

        $this->cache[$objNum] = $obj;

        return $obj;
    }

    /**
     * Return the object number of the document catalog (/Root).
     */
    public function getRootObjectNumber(): int
    {
        $root = $this->trailer->get('Root');
        if (!$root instanceof PdfReference) {
            throw new ParseException('Trailer /Root is not an indirect reference');
        }

        return $root->getObjectNumber();
    }

    /**
     * Return the generation number for the given object number.
     */
    public function getGenerationNumber(int $objNum): int
    {
        $entry = $this->xref->getEntry($objNum);

        return $entry?->generation ?? 0;
    }

    /**
     * Return the highest object number currently known to the xref.
     */
    public function getMaxObjectNumber(): int
    {
        return $this->xref->getMaxObjectNumber();
    }

    // -----------------------------------------------------------------------
    // startxref / xref chain
    // -----------------------------------------------------------------------

    private function findStartXref(): int
    {
        // Search the last 1 KB for 'startxref'
        $tail   = substr($this->data, max(0, strlen($this->data) - 1024));
        $offset = strrpos($tail, 'startxref');
        if ($offset === false) {
            throw new ParseException('Could not find startxref marker');
        }

        $lexer = new Lexer($tail, $offset + strlen('startxref'));
        $t     = $lexer->next();
        if ($t->type !== Token::TYPE_INTEGER) {
            throw new ParseException('Invalid startxref offset value');
        }

        return (int) $t->value;
    }

    /**
     * Parse the xref table / xref stream starting at $offset, following the
     * /Prev chain, and merge all entries into $this->xref.
     *
     * Returns the first (newest) trailer dictionary encountered.
     */
    private function parseXrefChain(int $offset): PdfDictionary
    {
        $firstTrailer = null;
        $visited      = [];

        while (true) {
            if (in_array($offset, $visited, true)) {
                break; // cycle guard
            }
            $visited[] = $offset;

            $lexer  = new Lexer($this->data, $offset);
            $lexer->skipWhitespace();
            $saved  = $lexer->getPosition();
            $firstToken = $lexer->next();

            if ($firstToken->type === Token::TYPE_KEYWORD && $firstToken->value === 'xref') {
                // Traditional cross-reference table
                [$trailer] = $this->parseTraditionalXref($lexer);
            } elseif ($firstToken->type === Token::TYPE_INTEGER) {
                // Cross-reference stream object
                $lexer->setPosition($saved);
                $trailer = $this->parseXrefStream($lexer);
            } else {
                throw new ParseException("Unexpected token at xref offset {$offset}: {$firstToken->value}");
            }

            if ($firstTrailer === null) {
                $firstTrailer = $trailer;
            }

            $prev = $trailer->get('Prev');
            if ($prev instanceof PdfInteger) {
                $offset = $prev->getValue();
            } else {
                break;
            }
        }

        if ($firstTrailer === null) {
            throw new ParseException('No trailer found');
        }

        return $firstTrailer;
    }

    // -----------------------------------------------------------------------
    // Traditional xref table
    // -----------------------------------------------------------------------

    /**
     * Parse a traditional xref table (after the 'xref' keyword has been consumed).
     * Returns [trailer dictionary].
     */
    private function parseTraditionalXref(Lexer $lexer): array
    {
        while (true) {
            $lexer->skipWhitespace();
            $t = $lexer->next();

            if ($t->type === Token::TYPE_KEYWORD && $t->value === 'trailer') {
                break;
            }

            if ($t->type !== Token::TYPE_INTEGER) {
                throw new ParseException("Expected xref section header integer, got: {$t->value}");
            }

            $startObj = (int) $t->value;
            $count    = $lexer->next();
            if ($count->type !== Token::TYPE_INTEGER) {
                throw new ParseException('Expected xref section count');
            }

            $this->readTraditionalXrefSection($lexer, $startObj, (int) $count->value);
        }

        // Parse trailer dictionary
        $objParser = new ObjectParser($lexer);
        $trailerObj = $objParser->parse();
        if (!$trailerObj instanceof PdfDictionary) {
            throw new ParseException('Trailer is not a dictionary');
        }

        return [$trailerObj];
    }

    private function readTraditionalXrefSection(Lexer $lexer, int $startObj, int $count): void
    {
        // Each entry is exactly 20 bytes: "oooooooooo ggggg f/n \n"
        // But we parse flexibly using readLine() to handle CR+LF vs LF differences.
        for ($i = 0; $i < $count; $i++) {
            $objNum = $startObj + $i;
            $line   = trim($lexer->readLine());

            if ($line === '') {
                // Some PDFs have inconsistent line endings; try again
                $line = trim($lexer->readLine());
            }

            if (strlen($line) < 17) {
                continue;
            }

            $parts = preg_split('/\s+/', $line);
            if (count($parts) < 3) {
                continue;
            }

            $offset     = (int) $parts[0];
            $generation = (int) $parts[1];
            $typeChar   = $parts[2];

            $type = ($typeChar === 'n') ? XrefEntry::TYPE_IN_USE : XrefEntry::TYPE_FREE;
            $this->xref->addEntry($objNum, new XrefEntry($type, $offset, $generation));
        }
    }

    // -----------------------------------------------------------------------
    // Cross-reference stream (PDF 1.5+)
    // -----------------------------------------------------------------------

    /**
     * Parse a cross-reference stream object starting at the current lexer position.
     * Returns the stream's dictionary as the trailer.
     */
    private function parseXrefStream(Lexer $lexer): PdfDictionary
    {
        // Read "objNum genNum obj"
        $objNumToken = $lexer->next();
        $genNumToken = $lexer->next();
        $objKeyword  = $lexer->next();

        if ($objNumToken->type !== Token::TYPE_INTEGER
            || $genNumToken->type !== Token::TYPE_INTEGER
            || $objKeyword->type !== Token::TYPE_KEYWORD
            || $objKeyword->value !== 'obj') {
            throw new ParseException('Expected indirect object header at xref stream offset');
        }

        $objParser  = new ObjectParser($lexer);
        $dictObj    = $objParser->parse();

        if (!$dictObj instanceof PdfDictionary) {
            throw new ParseException('Xref stream is not a dictionary');
        }

        $stream = $this->readStreamBody($lexer, $dictObj);

        $typeEntry = $dictObj->get('Type');
        if (!($typeEntry instanceof PdfName) || $typeEntry->getValue() !== 'XRef') {
            throw new ParseException('Object at startxref is not an XRef stream');
        }

        $this->parseXrefStreamData($stream, $dictObj);

        return $dictObj;
    }

    private function parseXrefStreamData(PdfStream $stream, PdfDictionary $dict): void
    {
        $data = $stream->getDecodedData();

        // /W [w1 w2 w3] — field widths
        $wObj = $dict->get('W');
        if (!$wObj instanceof PdfArray || $wObj->count() < 3) {
            throw new ParseException('XRef stream missing valid /W array');
        }

        $w = [];
        foreach ($wObj->getItems() as $item) {
            $w[] = ($item instanceof PdfInteger) ? $item->getValue() : 0;
        }
        [$w1, $w2, $w3] = $w;
        $entrySize = $w1 + $w2 + $w3;

        if ($entrySize === 0) {
            return;
        }

        // /Index [start count ...] — defaults to [0 /Size]
        $indexPairs = [];
        $indexObj   = $dict->get('Index');
        if ($indexObj instanceof PdfArray) {
            $items = $indexObj->getItems();
            for ($i = 0, $c = count($items); $i + 1 < $c; $i += 2) {
                $s  = ($items[$i] instanceof PdfInteger)     ? $items[$i]->getValue()     : 0;
                $cn = ($items[$i + 1] instanceof PdfInteger) ? $items[$i + 1]->getValue() : 0;
                $indexPairs[] = [$s, $cn];
            }
        }

        if (empty($indexPairs)) {
            $sizeObj = $dict->get('Size');
            $size    = ($sizeObj instanceof PdfInteger) ? $sizeObj->getValue() : 0;
            $indexPairs = [[0, $size]];
        }

        $pos = 0;
        foreach ($indexPairs as [$startObj, $count]) {
            for ($i = 0; $i < $count; $i++) {
                if ($pos + $entrySize > strlen($data)) {
                    break 2;
                }

                $f1 = $this->readBigEndian($data, $pos, $w1);
                $f2 = $this->readBigEndian($data, $pos + $w1, $w2);
                $f3 = $this->readBigEndian($data, $pos + $w1 + $w2, $w3);
                $pos += $entrySize;

                $objNum = $startObj + $i;

                // Default type is 1 (in-use) when w1 === 0
                $type = ($w1 === 0) ? XrefEntry::TYPE_IN_USE : $f1;

                $entry = match ($type) {
                    XrefEntry::TYPE_FREE       => new XrefEntry(XrefEntry::TYPE_FREE, $f2, $f3),
                    XrefEntry::TYPE_IN_USE     => new XrefEntry(XrefEntry::TYPE_IN_USE, $f2, $f3),
                    XrefEntry::TYPE_COMPRESSED => new XrefEntry(XrefEntry::TYPE_COMPRESSED, $f2, $f3),
                    default => null,
                };

                if ($entry !== null) {
                    $this->xref->addEntry($objNum, $entry);
                }
            }
        }
    }

    private function readBigEndian(string $data, int $offset, int $width): int
    {
        $value = 0;
        for ($i = 0; $i < $width; $i++) {
            $value = ($value << 8) | ord($data[$offset + $i]);
        }

        return $value;
    }

    // -----------------------------------------------------------------------
    // Object parsing
    // -----------------------------------------------------------------------

    /**
     * Parse the indirect object at the given byte offset.
     * Expects: "N G obj … endobj" (stream objects are returned as PdfStream).
     */
    private function parseObjectAt(int $offset, int $expectedObjNum): PdfObject
    {
        $lexer     = new Lexer($this->data, $offset);
        $objParser = new ObjectParser($lexer);

        $t1 = $lexer->next();
        $t2 = $lexer->next();
        $t3 = $lexer->next();

        if ($t1->type !== Token::TYPE_INTEGER
            || $t2->type !== Token::TYPE_INTEGER
            || $t3->type !== Token::TYPE_KEYWORD
            || $t3->value !== 'obj') {
            throw new ParseException(
                "Expected 'N G obj' at offset {$offset}, got: {$t1->value} {$t2->value} {$t3->value}"
            );
        }

        $obj = $objParser->parse();

        // Check if a stream follows
        if ($obj instanceof PdfDictionary) {
            $saved = $lexer->getPosition();
            $lexer->skipWhitespace();
            $next = $lexer->next();
            if ($next->type === Token::TYPE_KEYWORD && $next->value === 'stream') {
                return $this->readStreamBody($lexer, $obj);
            }
            $lexer->setPosition($saved);
        }

        return $obj;
    }

    /**
     * Read the stream body after the dictionary and 'stream' keyword have been consumed.
     * Handles the /Length value, which may be an indirect reference.
     */
    private function readStreamBody(Lexer $lexer, PdfDictionary $dict): PdfStream
    {
        // Skip exactly one newline (CR, LF, or CR+LF) that follows the 'stream' keyword
        $pos = $lexer->getPosition();
        $raw = $lexer->getData();
        if ($pos < strlen($raw) && $raw[$pos] === "\r") {
            $pos++;
        }
        if ($pos < strlen($raw) && $raw[$pos] === "\n") {
            $pos++;
        }
        $lexer->setPosition($pos);

        // Resolve /Length (may be a direct integer or an indirect reference)
        $lengthObj = $dict->get('Length');
        if ($lengthObj === null) {
            throw new ParseException('Stream dictionary missing /Length');
        }

        if ($lengthObj instanceof PdfReference) {
            $resolved  = $this->getObject($lengthObj->getObjectNumber());
            $lengthObj = $resolved;
        }

        if (!$lengthObj instanceof PdfInteger) {
            throw new ParseException('Stream /Length is not an integer');
        }

        $length  = $lengthObj->getValue();
        $rawData = substr($raw, $lexer->getPosition(), $length);
        $lexer->setPosition($lexer->getPosition() + $length);

        return new PdfStream($dict, $rawData);
    }

    // -----------------------------------------------------------------------
    // Object streams (PDF 1.5+ compressed objects)
    // -----------------------------------------------------------------------

    /**
     * Retrieve object at index $indexInStream from object stream $streamObjNum.
     */
    private function getCompressedObject(int $streamObjNum, int $indexInStream): PdfObject
    {
        if (isset($this->objStreamCache[$streamObjNum][$indexInStream])) {
            return $this->objStreamCache[$streamObjNum][$indexInStream];
        }

        $streamObj = $this->getObject($streamObjNum);
        if (!$streamObj instanceof PdfStream) {
            throw new ParseException("Object stream {$streamObjNum} is not a stream");
        }

        $typeObj = $streamObj->getDictionary()->get('Type');
        if (!($typeObj instanceof PdfName) || $typeObj->getValue() !== 'ObjStm') {
            throw new ParseException("Object {$streamObjNum} is not an ObjStm");
        }

        $nObj = $streamObj->getDictionary()->get('N');
        $firstObj = $streamObj->getDictionary()->get('First');
        if (!$nObj instanceof PdfInteger || !$firstObj instanceof PdfInteger) {
            throw new ParseException("ObjStm {$streamObjNum} has invalid /N or /First");
        }

        $n     = $nObj->getValue();
        $first = $firstObj->getValue();
        $body  = $streamObj->getDecodedData();

        // Parse the header: N pairs of (objNum, offsetFromFirst)
        $headerLexer = new Lexer($body, 0);
        $pairs       = [];
        for ($i = 0; $i < $n; $i++) {
            $oNum = $headerLexer->next();
            $oOff = $headerLexer->next();
            if ($oNum->type !== Token::TYPE_INTEGER || $oOff->type !== Token::TYPE_INTEGER) {
                break;
            }
            $pairs[] = [(int) $oNum->value, (int) $oOff->value];
        }

        // Parse each object
        foreach ($pairs as $idx => [$oNum, $oOff]) {
            $objLexer  = new Lexer($body, $first + $oOff);
            $objParser = new ObjectParser($objLexer);
            $parsed    = $objParser->parse();

            $this->objStreamCache[$streamObjNum][$idx] = $parsed;
            $this->cache[$oNum]                        = $parsed;
        }

        if (!isset($this->objStreamCache[$streamObjNum][$indexInStream])) {
            throw new ParseException(
                "Index {$indexInStream} not found in object stream {$streamObjNum}"
            );
        }

        return $this->objStreamCache[$streamObjNum][$indexInStream];
    }
}
