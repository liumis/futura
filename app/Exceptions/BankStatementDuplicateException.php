<?php

namespace App\Exceptions;

class BankStatementDuplicateException extends BankStatementXmlException
{
    public static function alreadyImportedFile(?string $existingFilename = null): self
    {
        $suffix = filled($existingFilename) ? ' ('.$existingFilename.')' : '';

        return new self('This bank statement file was already imported'.$suffix.'. Duplicate files are not imported.');
    }

    public static function allLinesAlreadyImported(): self
    {
        return new self('All lines in this bank statement are already in the system. Duplicate statements are not imported.');
    }
}
