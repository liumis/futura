<?php

namespace App\Services;

use App\Models\InvoiceCode;
use Illuminate\Support\Facades\DB;

class InvoiceCodeImporter
{
    /**
     * @return array{created: int, updated: int, total: int}
     */
    public static function importFromFile(string $path): array
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException('Invoice codes file not found: '.$path);
        }

        return self::importFromText(file_get_contents($path) ?: '');
    }

    /**
     * @return array{created: int, updated: int, total: int}
     */
    public static function importFromText(string $text): array
    {
        $entries = self::parseEntries($text);

        return self::persistEntries($entries);
    }

    /**
     * @return list<array{code: string, name: string}>
     */
    public static function parseEntries(string $text): array
    {
        $entries = [];
        $current = null;

        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);

            if ($line === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s+(.+)$/u', $line, $matches)) {
                if ($current !== null) {
                    $entries[] = $current;
                }

                $current = [
                    'code' => $matches[1],
                    'name' => trim($matches[2]),
                ];

                continue;
            }

            if ($current !== null) {
                $current['name'] = trim($current['name'].' '.$line);
            }
        }

        if ($current !== null) {
            $entries[] = $current;
        }

        return $entries;
    }

    /**
     * @param  list<array{code: string, name: string}>  $entries
     * @return array{created: int, updated: int, total: int}
     */
    public static function persistEntries(array $entries): array
    {
        $created = 0;
        $updated = 0;

        usort($entries, function (array $left, array $right): int {
            $lengthCompare = strlen($left['code']) <=> strlen($right['code']);

            return $lengthCompare !== 0 ? $lengthCompare : strcmp($left['code'], $right['code']);
        });

        DB::transaction(function () use ($entries, &$created, &$updated): void {
            /** @var array<string, int> $codeToId */
            $codeToId = InvoiceCode::query()
                ->pluck('id', 'code')
                ->all();

            foreach ($entries as $entry) {
                $parentId = self::resolveParentId($entry['code'], $codeToId);

                $existingId = $codeToId[$entry['code']] ?? null;

                if ($existingId === null) {
                    $record = InvoiceCode::query()->create([
                        'code' => $entry['code'],
                        'name' => $entry['name'],
                        'parent_id' => $parentId,
                    ]);

                    $codeToId[$entry['code']] = $record->id;
                    $created++;

                    continue;
                }

                InvoiceCode::query()
                    ->whereKey($existingId)
                    ->update([
                        'name' => $entry['name'],
                        'parent_id' => $parentId,
                    ]);

                $updated++;
            }
        });

        return [
            'created' => $created,
            'updated' => $updated,
            'total' => count($entries),
        ];
    }

    /**
     * @param  array<string, int>  $codeToId
     */
    public static function resolveParentId(string $code, array $codeToId): ?int
    {
        $bestParentId = null;
        $bestLength = 0;

        foreach ($codeToId as $parentCode => $parentId) {
            if ($parentCode === $code) {
                continue;
            }

            if (str_starts_with($code, $parentCode) && strlen($parentCode) > $bestLength) {
                $bestLength = strlen($parentCode);
                $bestParentId = $parentId;
            }
        }

        return $bestParentId;
    }
}
