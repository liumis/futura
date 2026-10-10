<?php

namespace App\Services;

/**
 * Interprets warehouse Excel titles:
 * leather starts with "Dirbtinė oda", everything else is a catalog.
 */
class ExternalStockTitleParser
{
    public function isLeather(?string $title): bool
    {
        return preg_match('/^dirbtin[eė]\s+oda\b/iu', trim((string) $title)) === 1;
    }

    /**
     * @return array{collection: string, color_code: string, color_name: string, size: string}|null
     */
    public function parseLeather(?string $title): ?array
    {
        $title = trim((string) $title);
        if ($title === '' || ! $this->isLeather($title)) {
            return null;
        }

        $rest = preg_replace('/^dirbtin[eė]\s+oda\s+/iu', '', $title) ?? $title;
        $rest = trim(preg_replace('/\s+/', ' ', $rest) ?? $rest);

        if (preg_match('/^(.*?)\s*(\d+)\s*m(?:\s+([A-Za-z][A-Za-z0-9]*))?\s*$/iu', $rest, $matches) !== 1) {
            return null;
        }

        $before = trim($matches[1]);
        $size = $matches[2];
        $trailingCollection = isset($matches[3]) && $matches[3] !== ''
            ? strtoupper($matches[3])
            : null;

        if (preg_match('/^(.+?)\s+(\d+)\s*-\s*([A-Za-z][A-Za-z0-9]*)\s*$/u', $before, $parts) !== 1) {
            return null;
        }

        $head = strtoupper(trim($parts[1]));
        $colorCode = $parts[2];
        $colorName = strtoupper($parts[3]);
        $headHasDigit = preg_match('/\d/', $head) === 1;

        $collection = ($trailingCollection !== null && $headHasDigit)
            ? $trailingCollection
            : $head;

        if ($collection === '' || $colorCode === '' || $colorName === '' || $size === '') {
            return null;
        }

        return [
            'collection' => $collection,
            'color_code' => $colorCode,
            'color_name' => $colorName,
            'size' => $size,
        ];
    }

    /**
     * @return array{collection: string, color_code: string, color_name: string}
     */
    public function parseCatalog(?string $title, ?string $code = null): array
    {
        $title = trim((string) $title);
        $code = trim((string) $code);
        $colorName = $title !== '' ? $title : $code;
        $collection = null;

        if (preg_match('/^katalogas\s+(.+)$/iu', $title, $matches) === 1) {
            $collection = trim($matches[1]);
        }

        if (($collection === null || $collection === '') && preg_match('#/?([A-Z0-9]+)/?KATALOG#i', strtoupper($code), $matches) === 1) {
            $collection = $matches[1];
        }

        if ($collection === null || $collection === '') {
            $collection = $colorName !== '' ? $colorName : 'Catalog';
        }

        if ($colorName === '') {
            $colorName = 'Katalogas '.$collection;
        }

        return [
            'collection' => $collection,
            'color_code' => 'CAT',
            'color_name' => $colorName,
        ];
    }

    public function isSamplesWarehouseHeader(?string $text): bool
    {
        $normalized = $this->normalizeHeaderText((string) $text);

        if ($normalized === '') {
            return false;
        }

        $hasSample = str_contains($normalized, 'pavyzdzi') || str_contains($normalized, 'sample');
        $hasWarehouse = str_contains($normalized, 'sandel') || str_contains($normalized, 'warehouse');

        return $hasSample && $hasWarehouse;
    }

    private function normalizeHeaderText(string $value): string
    {
        $value = strtr($value, [
            'ą' => 'a', 'č' => 'c', 'ę' => 'e', 'ė' => 'e', 'į' => 'i', 'š' => 's', 'ų' => 'u', 'ū' => 'u', 'ž' => 'z',
            'Ą' => 'A', 'Č' => 'C', 'Ę' => 'E', 'Ė' => 'E', 'Į' => 'I', 'Š' => 'S', 'Ų' => 'U', 'Ū' => 'U', 'Ž' => 'Z',
        ]);
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return $value;
    }
}
