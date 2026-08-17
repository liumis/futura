<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;

class WriteOffDocumentPdfTranslator
{
    /**
     * @return array<string, string>
     */
    public static function strings(InvoiceLanguage $language): array
    {
        return match ($language) {
            InvoiceLanguage::Lithuanian => [
                'title' => 'Nurašymo dokumentas',
                'document_no' => 'Dokumento Nr.:',
                'date' => 'Data:',
                'prepared_by' => 'Parengė:',
                'company_id' => 'Įmonės kodas:',
                'product_code' => 'Kodas',
                'collection' => 'Kolekcija',
                'color' => 'Spalva',
                'quantity' => 'Kiekis',
                'total_written_off' => 'Iš viso nurašyta',
                'footer_generated' => 'Sugeneruota',
                'footer_lines' => 'eil.',
            ],
            InvoiceLanguage::English => [
                'title' => 'Write-off document',
                'document_no' => 'Document no.:',
                'date' => 'Date:',
                'prepared_by' => 'Prepared by:',
                'company_id' => 'Company ID:',
                'product_code' => 'Code',
                'collection' => 'Collection',
                'color' => 'Color',
                'quantity' => 'Quantity',
                'total_written_off' => 'Total written off',
                'footer_generated' => 'Generated',
                'footer_lines' => 'line(s)',
            ],
        };
    }
}
