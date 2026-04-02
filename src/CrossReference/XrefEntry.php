<?php

declare(strict_types=1);

namespace Typdf\CrossReference;

class XrefEntry
{
    /** Object is free (deleted). */
    public const TYPE_FREE = 0;

    /** Object is in use at a byte offset in the file. */
    public const TYPE_IN_USE = 1;

    /** Object is compressed inside an object stream. */
    public const TYPE_COMPRESSED = 2;

    /**
     * @param int $type       One of the TYPE_* constants.
     * @param int $offset     TYPE_IN_USE: byte offset in file.
     *                        TYPE_COMPRESSED: object number of the containing object stream.
     * @param int $generation TYPE_IN_USE: generation number.
     *                        TYPE_COMPRESSED: index of this object within the stream.
     */
    public function __construct(
        public readonly int $type,
        public readonly int $offset,
        public readonly int $generation,
    ) {
    }
}
