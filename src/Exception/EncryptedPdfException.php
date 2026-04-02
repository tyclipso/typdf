<?php

declare(strict_types=1);

namespace Typdf\Exception;

class EncryptedPdfException extends PdfException
{
    public function __construct()
    {
        parent::__construct('Encrypted PDFs are not supported');
    }
}
