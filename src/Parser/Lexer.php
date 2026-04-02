<?php

declare(strict_types=1);

namespace Typdf\Parser;

/**
 * Tokenises a PDF byte string at a given position.
 *
 * All PDF whitespace characters (NUL, HT, LF, FF, CR, SP) and comments
 * (% … newline) are silently skipped between tokens.
 */
class Lexer
{
    private int $pos;
    private int $length;

    private static array $whitespace = ["\x00", "\t", "\n", "\x0C", "\r", ' '];
    private static array $delimiters = ['(', ')', '<', '>', '[', ']', '{', '}', '/', '%'];

    public function __construct(private readonly string $data, int $offset = 0)
    {
        $this->pos    = $offset;
        $this->length = strlen($data);
    }

    public function getPosition(): int
    {
        return $this->pos;
    }

    public function setPosition(int $pos): void
    {
        $this->pos = $pos;
    }

    public function getData(): string
    {
        return $this->data;
    }

    public function skipWhitespace(): void
    {
        while ($this->pos < $this->length) {
            $ch = $this->data[$this->pos];
            if (in_array($ch, self::$whitespace, true)) {
                $this->pos++;
            } elseif ($ch === '%') {
                $this->skipComment();
            } else {
                break;
            }
        }
    }

    private function skipComment(): void
    {
        while ($this->pos < $this->length
            && $this->data[$this->pos] !== "\n"
            && $this->data[$this->pos] !== "\r") {
            $this->pos++;
        }
    }

    /** Return the next token without consuming it. */
    public function peek(): Token
    {
        $saved = $this->pos;
        $t     = $this->next();
        $this->pos = $saved;

        return $t;
    }

    /** Consume and return the next token. */
    public function next(): Token
    {
        $this->skipWhitespace();

        if ($this->pos >= $this->length) {
            return new Token(Token::TYPE_EOF, '');
        }

        $ch = $this->data[$this->pos];

        // << or >>
        if ($ch === '<' && $this->pos + 1 < $this->length && $this->data[$this->pos + 1] === '<') {
            $this->pos += 2;
            return new Token(Token::TYPE_DICT_START, '<<');
        }
        if ($ch === '>' && $this->pos + 1 < $this->length && $this->data[$this->pos + 1] === '>') {
            $this->pos += 2;
            return new Token(Token::TYPE_DICT_END, '>>');
        }

        // Hex string <…>
        if ($ch === '<') {
            return $this->readHexString();
        }

        // Array delimiters
        if ($ch === '[') {
            $this->pos++;
            return new Token(Token::TYPE_ARR_START, '[');
        }
        if ($ch === ']') {
            $this->pos++;
            return new Token(Token::TYPE_ARR_END, ']');
        }

        // Literal string (…)
        if ($ch === '(') {
            return $this->readLiteralString();
        }

        // Name /…
        if ($ch === '/') {
            return $this->readName();
        }

        // Number (possibly starting with - + .)
        if (ctype_digit($ch) || $ch === '-' || $ch === '+' || $ch === '.') {
            return $this->readNumber();
        }

        // Keyword (alphabetic start)
        if (ctype_alpha($ch)) {
            return $this->readKeyword();
        }

        // Unknown – skip and recurse
        $this->pos++;
        return $this->next();
    }

    // -----------------------------------------------------------------------
    // Token readers
    // -----------------------------------------------------------------------

    private function readHexString(): Token
    {
        $this->pos++; // skip <
        $hex = '';

        while ($this->pos < $this->length && $this->data[$this->pos] !== '>') {
            $ch = $this->data[$this->pos++];
            if (!in_array($ch, self::$whitespace, true)) {
                $hex .= $ch;
            }
        }
        $this->pos++; // skip >

        if (strlen($hex) % 2 !== 0) {
            $hex .= '0';
        }

        $bytes = '';
        for ($i = 0, $l = strlen($hex); $i < $l; $i += 2) {
            $bytes .= chr((int) hexdec(substr($hex, $i, 2)));
        }

        return new Token(Token::TYPE_STRING, $bytes);
    }

    private function readLiteralString(): Token
    {
        $this->pos++; // skip (
        $result = '';
        $depth  = 1;

        while ($this->pos < $this->length && $depth > 0) {
            $ch = $this->data[$this->pos];

            if ($ch === '\\') {
                $this->pos++;
                if ($this->pos >= $this->length) {
                    break;
                }
                $esc = $this->data[$this->pos];
                $this->pos++;

                if (ctype_digit($esc)) {
                    // Octal: \d, \dd, \ddd
                    $oct = $esc;
                    for ($i = 0; $i < 2 && $this->pos < $this->length && ctype_digit($this->data[$this->pos]); $i++) {
                        $oct .= $this->data[$this->pos++];
                    }
                    $result .= chr((int) octdec($oct));
                } elseif ($esc === "\r") {
                    // Line continuation: skip \r and optional \n
                    if ($this->pos < $this->length && $this->data[$this->pos] === "\n") {
                        $this->pos++;
                    }
                } elseif ($esc === "\n") {
                    // Line continuation: skip \n
                } else {
                    $result .= match ($esc) {
                        'n'  => "\n",
                        'r'  => "\r",
                        't'  => "\t",
                        'b'  => "\x08",
                        'f'  => "\x0C",
                        '('  => '(',
                        ')'  => ')',
                        '\\' => '\\',
                        default => $esc,
                    };
                }
            } elseif ($ch === '(') {
                $depth++;
                $result .= $ch;
                $this->pos++;
            } elseif ($ch === ')') {
                $depth--;
                if ($depth > 0) {
                    $result .= $ch;
                }
                $this->pos++;
            } elseif ($ch === "\r") {
                // Normalise CR and CR+LF to LF inside strings
                $result .= "\n";
                $this->pos++;
                if ($this->pos < $this->length && $this->data[$this->pos] === "\n") {
                    $this->pos++;
                }
            } else {
                $result .= $ch;
                $this->pos++;
            }
        }

        return new Token(Token::TYPE_STRING, $result);
    }

    private function readName(): Token
    {
        $this->pos++; // skip /
        $name = '';

        while ($this->pos < $this->length) {
            $ch = $this->data[$this->pos];
            if (in_array($ch, self::$whitespace, true) || in_array($ch, self::$delimiters, true)) {
                break;
            }
            if ($ch === '#' && $this->pos + 2 < $this->length) {
                $hex = substr($this->data, $this->pos + 1, 2);
                if (ctype_xdigit($hex)) {
                    $name .= chr((int) hexdec($hex));
                    $this->pos += 3;
                    continue;
                }
            }
            $name .= $ch;
            $this->pos++;
        }

        return new Token(Token::TYPE_NAME, $name);
    }

    private function readNumber(): Token
    {
        $start  = $this->pos;
        $isReal = false;

        if ($this->data[$this->pos] === '+' || $this->data[$this->pos] === '-') {
            $this->pos++;
        }

        while ($this->pos < $this->length) {
            $ch = $this->data[$this->pos];
            if (ctype_digit($ch)) {
                $this->pos++;
            } elseif ($ch === '.' && !$isReal) {
                $isReal = true;
                $this->pos++;
            } else {
                break;
            }
        }

        $str = substr($this->data, $start, $this->pos - $start);

        if ($str === '' || $str === '-' || $str === '+') {
            // Not a valid number — treat as keyword
            return new Token(Token::TYPE_KEYWORD, $str);
        }

        return $isReal
            ? new Token(Token::TYPE_REAL, (float) $str)
            : new Token(Token::TYPE_INTEGER, (int) $str);
    }

    private function readKeyword(): Token
    {
        $start = $this->pos;

        while ($this->pos < $this->length) {
            $ch = $this->data[$this->pos];
            if (in_array($ch, self::$whitespace, true) || in_array($ch, self::$delimiters, true)) {
                break;
            }
            $this->pos++;
        }

        return new Token(Token::TYPE_KEYWORD, substr($this->data, $start, $this->pos - $start));
    }

    /**
     * Read from the current position to the end of the current line.
     * The trailing newline characters are consumed but not included in the return value.
     */
    public function readLine(): string
    {
        $start = $this->pos;

        while ($this->pos < $this->length
            && $this->data[$this->pos] !== "\n"
            && $this->data[$this->pos] !== "\r") {
            $this->pos++;
        }

        $line = substr($this->data, $start, $this->pos - $start);

        if ($this->pos < $this->length) {
            if ($this->data[$this->pos] === "\r"
                && $this->pos + 1 < $this->length
                && $this->data[$this->pos + 1] === "\n") {
                $this->pos += 2;
            } else {
                $this->pos++;
            }
        }

        return $line;
    }
}
