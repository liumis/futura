<?php

namespace App\Services;

use App\Models\CompanySetting;
use Barryvdh\DomPDF\Facade\Pdf;

class Gpm313PdfGenerator
{
    public static function generate(int $year, int $month): string
    {
        return Pdf::loadView('pdf.gpm313-declaration', self::build($year, $month))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month): array
    {
        $report = Gpm313ReportBuilder::build($year, $month);
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
            'companyIdCells' => self::charCells($report['company_id'], 11),
            'yearCells' => self::charCells((string) $year, 4),
            'monthCells' => self::charCells((string) $month, 2),
            'field5Cells' => self::amountCells($report['field5']),
            'field6Cells' => self::amountCells($report['field6']),
            'field7Cells' => self::amountCells($report['field7']),
            'field8Cells' => self::amountCells($report['field8']),
            'field9Cells' => self::amountCells($report['field9']),
            'field10Cells' => self::amountCells($report['field10']),
            'field11Cells' => self::amountCells($report['field11']),
            'field12Cells' => self::amountCells($report['field12']),
            'generatedAt' => now()->format('Y-m-d H:i'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function charCells(string $value, int $length): array
    {
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        $padded = str_pad($value, $length, ' ', STR_PAD_LEFT);

        return str_split(substr($padded, -$length));
    }

    /**
     * @return list<string>
     */
    public static function amountCells(float $amount, int $integerDigits = 8): array
    {
        $cents = (int) round(abs($amount) * 100);
        $integer = (string) intdiv($cents, 100);
        $fraction = str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
        $integer = str_pad($integer, $integerDigits, ' ', STR_PAD_LEFT);

        $cells = str_split($integer);
        $cells[] = ',';
        $cells[] = $fraction[0];
        $cells[] = $fraction[1];

        if ($amount < 0 && isset($cells[0])) {
            $cells[0] = '-';
        }

        return $cells;
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
