<?php

declare(strict_types=1);

namespace Typdf\Parser;

/**
 * A single token produced by the PDF lexer.
 */
class Token
{
    public const TYPE_INTEGER    = 'integer';
    public const TYPE_REAL       = 'real';
    public const TYPE_NAME       = 'name';
    public const TYPE_STRING     = 'string';
    public const TYPE_KEYWORD    = 'keyword';
    public const TYPE_DICT_START = 'dict_start';
    public const TYPE_DICT_END   = 'dict_end';
    public const TYPE_ARR_START  = 'arr_start';
    public const TYPE_ARR_END    = 'arr_end';
    public const TYPE_EOF        = 'eof';

    public function __construct(
        public readonly string $type,
        public readonly string|int|float $value,
    ) {
    }
}
