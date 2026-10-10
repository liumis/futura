<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Reads warehouse balance Excel files from the external accounting system
 * (Prekė / Kiekis / Vnt. kaina, including classic .xls).
 */
class ExternalStockExcelParser
{
    public function __construct(
        protected ExternalStockTitleParser $titles = new ExternalStockTitleParser,
    ) {}

    /**
     * @return list<array{code: string, name: ?string, quantity: float, unit: ?string, price: ?float, row: int, section: 'stock'|'reservation'}>
     */
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('Excel file was not found.');
        }

        $spreadsheet = IOFactory::load($path);

        try {
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = (int) $sheet->getHighestDataRow();
            $highestColumn = $sheet->getHighestDataColumn();
            $highestColumnIndex = Coordinate::columnIndexFromString($highestColumn);

            if ($highestRow < 1 || $highestColumnIndex < 1) {
                return [];
            }

            $header = $this->findHeader($sheet, $highestRow, $highestColumnIndex);
            $codeCol = $header['code'];
            $qtyCol = $header['quantity'];
            $nameCol = $header['name'];
            $unitCol = $header['unit'];
            $priceCol = $header['price'];
            $startRow = $header['row'] + 1;

            $rows = [];
            $seen = [
                'stock' => [],
                'reservation' => [],
            ];
            $section = 'stock';

            for ($row = 1; $row < $startRow; $row++) {
                if ($this->rowIsSamplesWarehouseHeader($sheet, $row, $highestColumnIndex)) {
                    $section = 'reservation';
                    break;
                }
            }

            for ($row = $startRow; $row <= $highestRow; $row++) {
                if ($this->rowIsSamplesWarehouseHeader($sheet, $row, $highestColumnIndex)) {
                    $section = 'reservation';

                    continue;
                }

                $code = $this->cellString($sheet, $codeCol, $row);
                if ($this->isSkippableCode($code)) {
                    continue;
                }

                $quantity = $this->cellNumber($sheet, $qtyCol, $row);
                if ($quantity === null) {
                    continue;
                }

                $normalized = $this->normalizeCode($code);
                if ($normalized === '' || isset($seen[$section][$normalized])) {
                    continue;
                }

                $seen[$section][$normalized] = true;
                $unit = $unitCol !== null ? $this->cellString($sheet, $unitCol, $row) : null;
                $name = $nameCol !== null ? $this->cellString($sheet, $nameCol, $row) : null;
                $price = $priceCol !== null ? $this->cellNumber($sheet, $priceCol, $row) : null;

                $rows[] = [
                    'code' => $code,
                    'name' => filled($name) ? $name : null,
                    'quantity' => $quantity,
                    'unit' => filled($unit) ? $unit : null,
                    'price' => $price,
                    'row' => $row,
                    'section' => $section,
                ];
            }

            return $rows;
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    public function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = str_replace(['\\'], '/', $code);

        return preg_replace('/[^A-Z0-9\/]/', '', $code) ?? '';
    }

    /**
     * @return array{row: int, code: int, quantity: int, name: ?int, unit: ?int, price: ?int}
     */
    private function findHeader(Worksheet $sheet, int $highestRow, int $highestColumnIndex): array
    {
        $scanTo = min($highestRow, 40);

        for ($row = 1; $row <= $scanTo; $row++) {
            $codeCol = null;
            $qtyCol = null;
            $nameCol = null;
            $unitCol = null;
            $priceCol = null;

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $value = $this->normalizeHeader($this->cellString($sheet, $col, $row));
                if ($value === '') {
                    continue;
                }

                if ($codeCol === null && $this->isCodeHeader($value)) {
                    $codeCol = $col;

                    continue;
                }

                if ($qtyCol === null && $this->isQuantityHeader($value)) {
                    $qtyCol = $col;

                    continue;
                }

                if ($nameCol === null && $this->isNameHeader($value)) {
                    $nameCol = $col;

                    continue;
                }

                if ($unitCol === null && $this->isUnitHeader($value)) {
                    $unitCol = $col;

                    continue;
                }

                if ($priceCol === null && $this->isPriceHeader($value)) {
                    $priceCol = $col;
                }
            }

            if ($codeCol !== null && $qtyCol !== null) {
                $guessedName = $this->guessNameColumn(
                    $sheet,
                    $row + 1,
                    $highestRow,
                    $highestColumnIndex,
                    $codeCol,
                    $qtyCol,
                    $priceCol,
                    $unitCol,
                );

                $header = [
                    'row' => $row,
                    'code' => $codeCol,
                    'quantity' => $qtyCol,
                    'name' => $guessedName ?? $nameCol ?? ($highestColumnIndex >= 3 ? 3 : null),
                    'unit' => $unitCol,
                    'price' => $priceCol,
                ];

                if ($header['unit'] === null) {
                    $header['unit'] = $this->guessUnitColumn(
                        $sheet,
                        $row + 1,
                        $highestRow,
                        $highestColumnIndex,
                        $codeCol,
                        $qtyCol,
                        $priceCol,
                    );
                }

                return $header;
            }
        }

        $quantityCol = $this->guessQuantityColumn($sheet, $highestRow, $highestColumnIndex);
        $guessedName = $this->guessNameColumn($sheet, 1, $highestRow, $highestColumnIndex, 1, $quantityCol, null, null);

        return [
            'row' => 0,
            'code' => 1,
            'quantity' => $quantityCol,
            'name' => $guessedName ?? ($highestColumnIndex >= 3 ? 3 : null),
            'unit' => $this->guessUnitColumn($sheet, 1, $highestRow, $highestColumnIndex, 1, $quantityCol, null),
            'price' => null,
        ];
    }

    private function guessQuantityColumn(Worksheet $sheet, int $highestRow, int $highestColumnIndex): int
    {
        $counts = [];
        $scanTo = min($highestRow, 80);

        for ($row = 1; $row <= $scanTo; $row++) {
            $code = $this->cellString($sheet, 1, $row);
            if ($this->isSkippableCode($code)) {
                continue;
            }

            for ($col = 2; $col <= $highestColumnIndex; $col++) {
                if ($this->cellNumber($sheet, $col, $row) !== null) {
                    $counts[$col] = ($counts[$col] ?? 0) + 1;
                    break;
                }
            }
        }

        if ($counts === []) {
            return 2;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    private function guessUnitColumn(
        Worksheet $sheet,
        int $startRow,
        int $highestRow,
        int $highestColumnIndex,
        int $codeCol,
        int $qtyCol,
        ?int $priceCol,
    ): ?int {
        $counts = [];
        $scanTo = min($highestRow, max($startRow, 1) + 80);
        $skip = array_filter([$codeCol, $qtyCol, $priceCol], fn ($col): bool => $col !== null);

        for ($row = max($startRow, 1); $row <= $scanTo; $row++) {
            $code = $this->cellString($sheet, $codeCol, $row);
            if ($this->isSkippableCode($code)) {
                continue;
            }

            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                if (in_array($col, $skip, true)) {
                    continue;
                }

                if ($this->looksLikeUnit($this->cellString($sheet, $col, $row))) {
                    $counts[$col] = ($counts[$col] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    private function looksLikeUnit(string $value): bool
    {
        $normalized = $this->normalizeHeader($value);

        return in_array($normalized, ['m', 'lm', 'vnt', 'pcs', 'pc', 'un', 'unit', 'metr', 'meters', 'meter'], true);
    }

    private function guessNameColumn(
        Worksheet $sheet,
        int $startRow,
        int $highestRow,
        int $highestColumnIndex,
        int $codeCol,
        int $qtyCol,
        ?int $priceCol,
        ?int $unitCol,
    ): ?int {
        $counts = [];
        $scanTo = min($highestRow, max($startRow, 1) + 80);
        $skip = array_values(array_filter(
            [$codeCol, $qtyCol, $priceCol, $unitCol],
            fn ($col): bool => $col !== null,
        ));

        for ($row = max($startRow, 1); $row <= $scanTo; $row++) {
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                if (in_array($col, $skip, true)) {
                    continue;
                }

                if ($this->looksLikeProductTitle($this->cellString($sheet, $col, $row))) {
                    $counts[$col] = ($counts[$col] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (int) array_key_first($counts);
    }

    private function looksLikeProductTitle(string $value): bool
    {
        $normalized = $this->normalizeHeader($value);

        return str_starts_with($normalized, 'katalogas')
            || str_starts_with($normalized, 'dirbtine oda');
    }

    private function isSkippableCode(string $code): bool
    {
        $trimmed = trim($code);
        if ($trimmed === '') {
            return true;
        }

        $normalized = $this->normalizeHeader($trimmed);

        if (in_array($normalized, ['preke', 'product', 'kodas', 'code'], true)) {
            return true;
        }

        if (preg_match('/sandel|warehouse|viso|total|centrinis|^data\b/i', $trimmed) === 1) {
            return true;
        }

        if (str_starts_with($trimmed, '|')) {
            return true;
        }

        return preg_match('/[A-Za-z0-9]/', $trimmed) !== 1;
    }

    private function rowIsSamplesWarehouseHeader(Worksheet $sheet, int $row, int $highestColumnIndex): bool
    {
        $scanTo = min($highestColumnIndex, 20);

        for ($col = 1; $col <= $scanTo; $col++) {
            if ($this->titles->isSamplesWarehouseHeader($this->cellString($sheet, $col, $row))) {
                return true;
            }
        }

        return false;
    }

    private function isCodeHeader(string $value): bool
    {
        return in_array($value, ['preke', 'prekes', 'product', 'products', 'kodas', 'code', 'sku'], true);
    }

    private function isQuantityHeader(string $value): bool
    {
        return in_array($value, ['kiekis', 'quantity', 'qty', 'amount', 'stock', 'likutis'], true);
    }

    private function isNameHeader(string $value): bool
    {
        return in_array($value, ['pavadinimas', 'name', 'prekes pavadinimas'], true);
    }

    private function isUnitHeader(string $value): bool
    {
        return in_array($value, ['vnt', 'unit', 'uom', 'mato vnt', 'matas'], true);
    }

    private function isPriceHeader(string $value): bool
    {
        return str_contains($value, 'kaina') || in_array($value, ['price', 'unit price', 'vnt kaina'], true);
    }

    private function normalizeHeader(string $value): string
    {
        $value = mb_strtolower(trim($this->stripDiacritics($value)));
        $value = str_replace(['.', ','], ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return trim($value);
    }

    private function stripDiacritics(string $value): string
    {
        return strtr($value, [
            'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
            'Ą' => 'A', 'Č' => 'C', 'Ę' => 'E', 'Ė' => 'E', 'Į' => 'I', 'Š' => 'S', 'Ų' => 'U', 'Ū' => 'U', 'Ž' => 'Z',
        ]);
    }

    private function cellString(Worksheet $sheet, int $column, int $row): string
    {
        $value = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row)->getCalculatedValue();

        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }

    private function cellNumber(Worksheet $sheet, int $column, int $row): ?float
    {
        $raw = $sheet->getCell(Coordinate::stringFromColumnIndex($column).$row)->getCalculatedValue();

        if ($raw === null || $raw === '') {
            return null;
        }

        if (is_numeric($raw)) {
            return round((float) $raw, 4);
        }

        $text = trim((string) $raw);
        $text = str_replace([' ', "\u{00A0}"], '', $text);
        $text = str_replace(',', '.', $text);

        if (! is_numeric($text)) {
            return null;
        }

        return round((float) $text, 4);
    }
}
