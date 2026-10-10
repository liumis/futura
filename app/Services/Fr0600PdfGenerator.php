<?php

namespace App\Services;

use App\Models\CompanySetting;
use Barryvdh\DomPDF\Facade\Pdf;

class Fr0600PdfGenerator
{
    public static function generate(int $year, int $month): string
    {
        return Pdf::loadView('pdf.fr0600-declaration', self::build($year, $month))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month): array
    {
        $report = Fr0600ReportBuilder::build($year, $month);
        $company = CompanySetting::instance();
        $fieldCells = [];

        foreach ($report['fields'] as $key => $amount) {
            $fieldCells[$key] = Gpm313PdfGenerator::amountCells((float) $amount);
        }

        return [
            ...$report,
            'logoDataUri' => self::logoDataUri(),
            'companyName' => $report['company_name'],
            'companyId' => $report['company_id'],
            'companyVat' => $report['company_vat'],
            'companyAddress' => $report['company_address'],
            'companyEmail' => $report['company_email'],
            'companyPhone' => $report['company_phone'],
            'directorLabel' => filled($company->contact_name) ? (string) $company->contact_name : '',
            'companyIdCells' => Gpm313PdfGenerator::charCells($report['company_id'], 11),
            'companyVatCells' => Gpm313PdfGenerator::charCells($report['company_vat'], 14),
            'yearCells' => Gpm313PdfGenerator::charCells((string) $year, 4),
            'monthCells' => Gpm313PdfGenerator::charCells((string) $month, 2),
            'fieldCells' => $fieldCells,
            'fieldMeta' => Fr0600ReportBuilder::fieldMeta(),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ];
    }

    private static function logoDataUri(): ?string
    {
        $pngPath = public_path('images/logo-invoice.png');

        if (is_readable($pngPath)) {
            $contents = file_get_contents($pngPath);
            if ($contents !== false) {
                return 'data:image/png;base64,'.base64_encode($contents);
            }
        }

        $svgPath = public_path('images/logo.svg');

        if (! is_readable($svgPath)) {
            return null;
        }

        $contents = file_get_contents($svgPath);

        return $contents !== false
            ? 'data:image/svg+xml;base64,'.base64_encode($contents)
            : null;
    }
}
