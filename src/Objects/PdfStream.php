<?php

declare(strict_types=1);

namespace Typdf\Objects;

use Typdf\Exception\ParseException;

class PdfStream extends PdfObject
{
    public function __construct(
        private readonly PdfDictionary $dictionary,
        private readonly string $rawData,
    ) {
    }

    public function getDictionary(): PdfDictionary
    {
        return $this->dictionary;
    }

    public function getRawData(): string
    {
        return $this->rawData;
    }

    /**
     * Decode the stream data by applying each filter listed in /Filter,
     * together with any corresponding /DecodeParms entry.
     */
    public function getDecodedData(): string
    {
        $filter = $this->dictionary->get('Filter');

        if ($filter === null) {
            return $this->rawData;
        }

        // Normalise /Filter to an ordered list of filter names
        $filters = [];
        if ($filter instanceof PdfName) {
            $filters[] = $filter->getValue();
        } elseif ($filter instanceof PdfArray) {
            foreach ($filter->getItems() as $f) {
                if ($f instanceof PdfName) {
                    $filters[] = $f->getValue();
                }
            }
        }

        // Normalise /DecodeParms to an array parallel to $filters.
        // Each entry is either a PdfDictionary or null (no params for that filter).
        $parmsArray = [];
        $dpObj = $this->dictionary->get('DecodeParms');
        if ($dpObj instanceof PdfDictionary) {
            // Single filter with a single parms dict
            $parmsArray = [$dpObj];
        } elseif ($dpObj instanceof PdfArray) {
            foreach ($dpObj->getItems() as $item) {
                $parmsArray[] = ($item instanceof PdfDictionary) ? $item : null;
            }
        }

        $data = $this->rawData;
        foreach ($filters as $i => $filterName) {
            $parms = $parmsArray[$i] ?? null;
            $data  = match ($filterName) {
                'FlateDecode'    => self::decodeFlate($data, $parms),
                'ASCIIHexDecode' => self::decodeAsciiHex($data),
                'ASCII85Decode'  => self::decodeAscii85($data),
                default          => throw new ParseException("Unsupported stream filter: {$filterName}"),
            };
        }

        return $data;
    }

    // -----------------------------------------------------------------------
    // Filter implementations
    // -----------------------------------------------------------------------

    /**
     * Inflate a FlateDecode stream.
     *
     * Handles two common real-world variations:
     *  1. Zlib-wrapped deflate (standard, most common — 0x78 0x9C / 0xDA header)
     *  2. Raw deflate without a zlib wrapper (produced by some tools)
     *
     * After inflation, the PNG predictor is reversed if /DecodeParms specifies
     * /Predictor >= 10.  Adobe Acrobat almost always applies predictor 12
     * (PNG Up) to cross-reference streams and object streams.
     */
    private static function decodeFlate(string $data, ?PdfDictionary $parms): string
    {
        // Remove stream header/footer, if present
        if (str_starts_with($data, "stream\r\n")) {
            $data = substr($data, 8);
        }
        if (str_ends_with($data, "\r\nendstream")) {
            $data = substr($data, 0, -11);
        }
        // Try standard zlib-wrapped deflate first
        $decoded = @zlib_decode($data);
        if ($decoded === false) {
            $decoded = @gzuncompress($data, 64_000_000);
        }
        // Workaround for https://www.php.net/manual/en/function.gzuncompress.php#79042
        if ($decoded === false) {
            $tempFile = tempnam(sys_get_temp_dir(), 'gzfix');
            if ($tempFile) {
                file_put_contents($tempFile, "\x1f\x8b\x08\x00\x00\x00\x00\x00".$data);
                $decoded = file_get_contents('compress.zlib://'.$tempFile);
                unlink($tempFile);
            }
        }

        if (!$decoded) {
            throw new ParseException('Failed to inflate FlateDecode stream');
        }

        if ($parms === null) {
            return $decoded;
        }

        $predictor = self::parmInt($parms, 'Predictor', 1);

        if ($predictor >= 10) {
            // PNG predictor family (values 10–15)
            $columns = self::parmInt($parms, 'Columns', 1);
            $colors  = self::parmInt($parms, 'Colors', 1);
            $bpc     = self::parmInt($parms, 'BitsPerComponent', 8);
            $decoded = self::reversePngPredictor($decoded, $columns, $colors, $bpc);
        } elseif ($predictor === 2) {
            // TIFF Predictor 2 (horizontal differencing)
            $columns = self::parmInt($parms, 'Columns', 1);
            $colors  = self::parmInt($parms, 'Colors', 1);
            $bpc     = self::parmInt($parms, 'BitsPerComponent', 8);
            $decoded = self::reverseTiffPredictor($decoded, $columns, $colors, $bpc);
        }

        return $decoded;
    }

    // -----------------------------------------------------------------------
    // PNG predictor reversal (PDF spec §7.4.4.4, predictor values 10–15)
    // -----------------------------------------------------------------------

    /**
     * Undo PNG prediction applied before deflating.
     *
     * The decoded-but-unpredicted data is organised as rows, each prefixed
     * by one byte indicating which PNG filter was used for that row:
     *   0 = None, 1 = Sub, 2 = Up, 3 = Average, 4 = Paeth
     *
     * For non-image data (xref streams, object streams) columns = total row
     * byte width, colors = 1, bpc = 8, so bytesPerPixel = 1.
     */
    private static function reversePngPredictor(
        string $data,
        int $columns,
        int $colors,
        int $bpc,
    ): string {
        // Bytes per "pixel" (sample unit) in the PNG sense
        $bpp     = (int) max(1, ceil($colors * $bpc / 8));
        $rowSize = (int) ceil($columns * $colors * $bpc / 8);
        $stride  = $rowSize + 1; // +1 for the leading filter byte

        $len     = strlen($data);
        $result  = '';
        $prevRow = str_repeat("\x00", $rowSize);

        for ($offset = 0; $offset + $stride <= $len; $offset += $stride) {
            $filterByte = ord($data[$offset]);
            $row        = substr($data, $offset + 1, $rowSize);
            $out        = '';

            switch ($filterByte) {
                case 0: // None
                    $out = $row;
                    break;

                case 1: // Sub — delta from left pixel
                    for ($i = 0; $i < $rowSize; $i++) {
                        $left = ($i >= $bpp) ? ord($out[$i - $bpp]) : 0;
                        $out .= chr((ord($row[$i]) + $left) & 0xFF);
                    }
                    break;

                case 2: // Up — delta from pixel above
                    for ($i = 0; $i < $rowSize; $i++) {
                        $out .= chr((ord($row[$i]) + ord($prevRow[$i])) & 0xFF);
                    }
                    break;

                case 3: // Average — delta from floor((left + above) / 2)
                    for ($i = 0; $i < $rowSize; $i++) {
                        $left  = ($i >= $bpp) ? ord($out[$i - $bpp]) : 0;
                        $above = ord($prevRow[$i]);
                        $out  .= chr((ord($row[$i]) + (int) (($left + $above) / 2)) & 0xFF);
                    }
                    break;

                case 4: // Paeth
                    for ($i = 0; $i < $rowSize; $i++) {
                        $left      = ($i >= $bpp) ? ord($out[$i - $bpp])      : 0;
                        $above     = ord($prevRow[$i]);
                        $upperLeft = ($i >= $bpp) ? ord($prevRow[$i - $bpp])  : 0;
                        $out      .= chr((ord($row[$i]) + self::paeth($left, $above, $upperLeft)) & 0xFF);
                    }
                    break;

                default:
                    $out = $row; // Unknown filter byte — pass through
            }

            $result  .= $out;
            $prevRow  = $out;
        }

        return $result;
    }

    /** Paeth predictor function (PNG spec). */
    private static function paeth(int $a, int $b, int $c): int
    {
        $p  = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);

        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return ($pb <= $pc) ? $b : $c;
    }

    // -----------------------------------------------------------------------
    // TIFF Predictor 2 reversal (PDF spec §7.4.4.4, predictor value 2)
    // -----------------------------------------------------------------------

    private static function reverseTiffPredictor(
        string $data,
        int $columns,
        int $colors,
        int $bpc,
    ): string {
        if ($bpc !== 8) {
            // Only 8-bit/component case is handled here
            return $data;
        }

        $rowSize = $columns * $colors;
        $len     = strlen($data);
        $result  = '';

        for ($offset = 0; $offset + $rowSize <= $len; $offset += $rowSize) {
            $row = substr($data, $offset, $rowSize);
            $out = '';

            for ($i = 0; $i < $rowSize; $i++) {
                $left = ($i >= $colors) ? ord($out[$i - $colors]) : 0;
                $out .= chr((ord($row[$i]) + $left) & 0xFF);
            }

            $result .= $out;
        }

        return $result;
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private static function parmInt(PdfDictionary $dict, string $key, int $default): int
    {
        $obj = $dict->get($key);

        return ($obj instanceof PdfInteger) ? $obj->getValue() : $default;
    }

    // -----------------------------------------------------------------------
    // Other filter implementations (unchanged)
    // -----------------------------------------------------------------------

    private static function decodeAsciiHex(string $data): string
    {
        $data = preg_replace('/\s+/', '', rtrim($data, '>'));
        if (strlen($data) % 2 !== 0) {
            $data .= '0';
        }

        $result = '';
        for ($i = 0, $len = strlen($data); $i < $len; $i += 2) {
            $result .= chr((int) hexdec(substr($data, $i, 2)));
        }

        return $result;
    }

    private static function decodeAscii85(string $data): string
    {
        $data   = rtrim($data, "~> \t\r\n");
        $result = '';
        $group  = '';

        for ($i = 0, $len = strlen($data); $i < $len; $i++) {
            $char = $data[$i];

            if (ctype_space($char)) {
                continue;
            }

            if ($char === 'z') {
                $result .= "\x00\x00\x00\x00";
                continue;
            }

            $group .= $char;

            if (strlen($group) === 5) {
                $n = 0;
                for ($j = 0; $j < 5; $j++) {
                    $n = $n * 85 + (ord($group[$j]) - 33);
                }
                $result .= chr(($n >> 24) & 0xFF)
                    . chr(($n >> 16) & 0xFF)
                    . chr(($n >> 8) & 0xFF)
                    . chr($n & 0xFF);
                $group = '';
            }
        }

        if ($group !== '') {
            $partial = strlen($group);
            $group   = str_pad($group, 5, 'u');
            $n       = 0;
            for ($j = 0; $j < 5; $j++) {
                $n = $n * 85 + (ord($group[$j]) - 33);
            }
            for ($j = 0; $j < $partial - 1; $j++) {
                $result .= chr(($n >> (24 - 8 * $j)) & 0xFF);
            }
        }

        return $result;
    }
}
