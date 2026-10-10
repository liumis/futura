<?php

namespace App\Services;

use App\Models\CompanySetting;
use Barryvdh\DomPDF\Facade\Pdf;

class Fr0564PdfGenerator
{
    public static function generate(int $year, int $month): string
    {
        return Pdf::loadView('pdf.fr0564-declaration', self::build($year, $month))
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public static function build(int $year, int $month): array
    {
        $report = Fr0564ReportBuilder::build($year, $month);
        $company = CompanySetting::instance();
        [$vatCountry, $vatNumber] = self::splitVat((string) $report['company_vat']);
        $filledDate = now()->format('Y-m-d');

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
            'companyIdCells' => self::leftCells($report['company_id'], 11),
            'companyVatCountryCells' => self::leftCells($vatCountry, 2),
            'companyVatNumberCells' => self::leftCells($vatNumber, 12),
            'yearCells' => Gpm313PdfGenerator::charCells((string) $year, 4),
            'monthCells' => Gpm313PdfGenerator::charCells((string) $month, 2),
            'quarterCells' => ['', ''],
            'filledDateCells' => self::leftCells(str_replace('-', '', $filledDate), 8),
            'field15Cells' => self::integerCells($report['field15']),
            'field16Cells' => self::integerCells($report['field16']),
            'field17Cells' => Gpm313PdfGenerator::charCells(
                $report['field17'] > 0 ? (string) $report['field17'] : '',
                2,
            ),
            'tableRows' => self::tableRows($report['lines']),
            'generatedAt' => now()->format('Y-m-d H:i'),
            'filledDate' => $filledDate,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    public static function splitVat(string $vat): array
    {
        $vat = strtoupper(preg_replace('/[\s.\-\/]/', '', $vat) ?? '');

        if (preg_match('/^([A-Z]{2})(.+)$/', $vat, $matches) === 1) {
            return [$matches[1], $matches[2]];
        }

        return ['', $vat];
    }

    /**
     * @return list<string>
     */
    public static function leftCells(string $value, int $length): array
    {
        $value = preg_replace('/\s+/', '', $value) ?? $value;
        $padded = str_pad($value, $length, ' ', STR_PAD_RIGHT);

        return str_split(substr($padded, 0, $length));
    }

    /**
     * @return list<string>
     */
    public static function integerCells(int|float|string $amount, int $length = 10): array
    {
        if ($amount === '' || $amount === null) {
            return array_fill(0, $length, '');
        }

        $value = (string) (int) round(abs((float) $amount));
        $padded = str_pad($value, $length, ' ', STR_PAD_LEFT);
        $cells = str_split(substr($padded, -$length));

        if ((float) $amount < 0 && isset($cells[0])) {
            $cells[0] = '-';
        }

        return $cells;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function tableRows(array $lines): array
    {
        $rows = [];

        foreach ($lines as $line) {
            $rows[] = [
                'row' => (string) $line['row'],
                'country_cells' => self::leftCells((string) $line['country'], 2),
                'vat_cells' => self::leftCells((string) $line['vat_number'], 12),
                'goods_cells' => self::integerCells($line['goods_int'] ?? 0, 8),
                'triangular_cells' => self::integerCells(
                    ($line['triangular_int'] ?? 0) > 0 ? $line['triangular_int'] : '',
                    8,
                ),
                'services_cells' => self::integerCells(
                    ($line['services_int'] ?? 0) > 0 ? $line['services_int'] : '',
                    8,
                ),
            ];
        }

        while (count($rows) < 12) {
            $rows[] = [
                'row' => (string) (count($rows) + 1),
                'country_cells' => self::leftCells('', 2),
                'vat_cells' => self::leftCells('', 12),
                'goods_cells' => self::integerCells('', 8),
                'triangular_cells' => self::integerCells('', 8),
                'services_cells' => self::integerCells('', 8),
            ];
        }

        return $rows;
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
