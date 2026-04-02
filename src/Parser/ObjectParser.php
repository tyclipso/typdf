<?php

declare(strict_types=1);

namespace Typdf\Parser;

use Typdf\Exception\ParseException;
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
    PdfString,
};

/**
 * Parses individual PDF objects from a token stream produced by the Lexer.
 *
 * Does NOT handle stream bodies or indirect object wrappers (obj … endobj);
 * those are handled by PdfParser.
 */
class ObjectParser
{
    public function __construct(private readonly Lexer $lexer)
    {
    }

    public function getLexer(): Lexer
    {
        return $this->lexer;
    }

    /**
     * Parse and return one PDF object from the current position.
     *
     * @throws ParseException
     */
    public function parse(): PdfObject
    {
        $token = $this->lexer->next();

        return $this->buildFromToken($token);
    }

    private function buildFromToken(Token $token): PdfObject
    {
        switch ($token->type) {
            case Token::TYPE_INTEGER:
                return $this->resolveInteger((int) $token->value);

            case Token::TYPE_REAL:
                return new PdfReal((float) $token->value);

            case Token::TYPE_NAME:
                return new PdfName((string) $token->value);

            case Token::TYPE_STRING:
                return new PdfString((string) $token->value);

            case Token::TYPE_DICT_START:
                return $this->parseDictionary();

            case Token::TYPE_ARR_START:
                return $this->parseArray();

            case Token::TYPE_KEYWORD:
                return match ((string) $token->value) {
                    'true'  => new PdfBoolean(true),
                    'false' => new PdfBoolean(false),
                    'null'  => new PdfNull(),
                    default => throw new ParseException("Unexpected keyword: {$token->value}"),
                };

            case Token::TYPE_EOF:
                throw new ParseException('Unexpected end of data while parsing object');

            default:
                throw new ParseException("Unexpected token type: {$token->type} value: {$token->value}");
        }
    }

    /**
     * An integer token may be the start of an indirect reference "N G R".
     * We peek ahead; if no reference follows, return a plain PdfInteger.
     */
    private function resolveInteger(int $objNum): PdfObject
    {
        $saved = $this->lexer->getPosition();

        $t1 = $this->lexer->next();
        if ($t1->type === Token::TYPE_INTEGER) {
            $genNum = (int) $t1->value;
            $t2     = $this->lexer->next();
            if ($t2->type === Token::TYPE_KEYWORD && $t2->value === 'R') {
                return new PdfReference($objNum, $genNum);
            }
        }

        $this->lexer->setPosition($saved);

        return new PdfInteger($objNum);
    }

    private function parseDictionary(): PdfDictionary
    {
        $dict = new PdfDictionary();

        while (true) {
            $t = $this->lexer->peek();

            if ($t->type === Token::TYPE_DICT_END) {
                $this->lexer->next();
                break;
            }
            if ($t->type === Token::TYPE_EOF) {
                throw new ParseException('Unexpected EOF inside dictionary');
            }

            // Key must be a name
            $keyToken = $this->lexer->next();
            if ($keyToken->type !== Token::TYPE_NAME) {
                throw new ParseException(
                    "Expected name key in dictionary, got {$keyToken->type}: {$keyToken->value}"
                );
            }

            $dict->set((string) $keyToken->value, $this->parse());
        }

        return $dict;
    }

    private function parseArray(): PdfArray
    {
        $array = new PdfArray();

        while (true) {
            $t = $this->lexer->peek();

            if ($t->type === Token::TYPE_ARR_END) {
                $this->lexer->next();
                break;
            }
            if ($t->type === Token::TYPE_EOF) {
                throw new ParseException('Unexpected EOF inside array');
            }

            $array->add($this->parse());
        }

        return $array;
    }
}
