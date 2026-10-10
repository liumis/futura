<?php

namespace App\Services;

use App\Models\CompanySetting;
use Barryvdh\DomPDF\Facade\Pdf;

class Gpm312PdfGenerator
{
    public static function generate(int $year): string
    {
        return Pdf::loadView('pdf.gpm312-declaration', self::build($year))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $year): array
    {
        $report = Gpm312ReportBuilder::build($year);
        $company = CompanySetting::instance();

        return [
            ...$report,
            'logoDataUri' => self::logoDataUri(),
            'companyName' => $report['company_name'],
            'companyId' => $report['company_id'],
            'companyAddress' => $report['company_address'],
            'companyEmail' => $report['company_email'],
            'companyPhone' => $report['company_phone'],
            'directorLabel' => filled($company->contact_name) ? (string) $company->contact_name : '',
            'companyIdCells' => Gpm313PdfGenerator::charCells($report['company_id'], 11),
            'yearCells' => Gpm313PdfGenerator::charCells((string) $year, 4),
            'field4Cells' => Gpm313PdfGenerator::amountCells($report['field4']),
            'field5Cells' => Gpm313PdfGenerator::amountCells($report['field5']),
            'field6Cells' => Gpm313PdfGenerator::amountCells($report['field6']),
            'field7Cells' => Gpm313PdfGenerator::amountCells($report['field7']),
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
