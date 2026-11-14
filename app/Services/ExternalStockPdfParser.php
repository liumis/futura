<?php

namespace App\Services;

use RuntimeException;
use Smalot\PdfParser\Parser as PdfParser;

/**
 * Reads Likučiai PDF exports (same Prekė / Kiekis / Vnt. kaina layout as Excel).
 *
 * @phpstan-type StockRow array{code: string, name: ?string, quantity: float, unit: ?string, price: ?float, row: int, section: 'stock'|'reservation'}
 */
class ExternalStockPdfParser
{
    public function __construct(
        protected ExternalStockTitleParser $titles = new ExternalStockTitleParser,
    ) {}

    /**
     * @return list<StockRow>
     */
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('PDF file was not found.');
        }

        try {
            $text = (new PdfParser)->parseFile($path)->getText();
        } catch (\Throwable $e) {
            throw new RuntimeException('Could not read stock PDF: '.$e->getMessage(), 0, $e);
        }

        return $this->parseText($text);
    }

    /**
     * @return list<StockRow>
     */
    public function parseText(string $text): array
    {
        $lines = preg_split("/\r\n|\n|\r/", $text) ?: [];
        $section = 'stock';
        $pending = [];
        $rows = [];
        $seen = [
            'stock' => [],
            'reservation' => [],
        ];
        $rowNum = 0;

        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $line = trim((string) $lines[$i]);
            if ($line === '') {
                continue;
            }

            if ($this->titles->isSamplesWarehouseHeader($line) || preg_match('/PAVYZD[ŽZ]I[ŲU]\s*-/iu', $line) === 1) {
                $section = 'reservation';
                $pending = [];

                continue;
            }

            if ($this->isBoilerplateLine($line)) {
                if (preg_match('/Viso\s+sad/iu', $line) === 1 || preg_match('/^UAB\b/iu', $line) === 1) {
                    $pending = [];
                }

                continue;
            }

            if (preg_match(
                '/^(?:([\d ]+,\d{2})([A-Z0-9\/\.]+)\t?)?(\d+(?:\.\d+)?)\s+(VNT|m)\s+(\d+,\d{2})(.+)$/iu',
                $line,
                $matches,
            ) === 1) {
                $sumCode = '';
                if (($matches[1] ?? '') !== '' || ($matches[2] ?? '') !== '') {
                    // Trailing collection left in pending from the previous product (e.g. PALOMA).
                    if ($pending !== [] && $rows !== []) {
                        $trail = implode(' ', $pending);
                        if ($this->looksLikeTrailingCollection($trail)) {
                            $last = count($rows) - 1;
                            $rows[$last]['name'] = trim((string) $rows[$last]['name'].' '.$trail);
                        }
                    }
                    $sumCode = ($matches[1] ?? '').($matches[2] ?? '');
                } elseif ($pending !== []) {
                    $sumCode = implode('', $pending);
                }
                $pending = [];

                $code = $this->codeFromSumCode($sumCode);
                $quantity = (float) $matches[3];
                $unit = strtoupper((string) $matches[4]);
                $price = $this->parseLtDecimal((string) $matches[5]);
                $name = trim((string) $matches[6]);

                // Soft line-wrap: "PALOMA" / collection hint on the next line(s).
                while ($i + 1 < $count) {
                    $next = trim((string) $lines[$i + 1]);
                    if ($next === '' || $this->isBoilerplateLine($next)) {
                        break;
                    }
                    if ($this->looksLikeQtyLine($next) || $this->looksLikeSumCodeLine($next)) {
                        break;
                    }
                    if ($this->titles->isSamplesWarehouseHeader($next) || preg_match('/PAVYZD[ŽZ]I[ŲU]\s*-/iu', $next) === 1) {
                        break;
                    }
                    if ($this->looksLikeTrailingCollection($next) || $this->looksLikeNameContinuation($name, $next)) {
                        $name = trim($name.' '.$next);
                        $i++;

                        continue;
                    }

                    break;
                }

                if ($code === '') {
                    $code = $this->inferCodeFromName($name) ?? '';
                }

                $normalized = $this->normalizeCode($code);
                if ($normalized === '' || isset($seen[$section][$normalized])) {
                    continue;
                }

                $seen[$section][$normalized] = true;
                $rowNum++;
                $rows[] = [
                    'code' => $code,
                    'name' => $name !== '' ? $name : null,
                    'quantity' => $quantity,
                    'unit' => $unit !== '' ? $unit : null,
                    'price' => $price,
                    'row' => $rowNum,
                    'section' => $section,
                ];

                continue;
            }

            // Lone page numbers between sections.
            if (preg_match('/^\d+$/', $line) === 1 && (int) $line <= 30 && $pending === []) {
                continue;
            }

            $pending[] = $line;
        }

        if ($pending !== [] && $rows !== []) {
            $trail = implode(' ', $pending);
            if ($this->looksLikeTrailingCollection($trail)) {
                $last = count($rows) - 1;
                $rows[$last]['name'] = trim((string) $rows[$last]['name'].' '.$trail);
            }
        }

        return $rows;
    }

    private function isBoilerplateLine(string $line): bool
    {
        return preg_match(
            '/^(UAB\b|Ringuvos|Im\. kodas|Įm\. kodas|Data\s*<=|Likučiai|SumaKiekis|LIK_DAT|CENTR\s*\.|Viso\b|\|__V__\|)/iu',
            $line,
        ) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}$/', $line) === 1;
    }

    private function looksLikeQtyLine(string $line): bool
    {
        return preg_match('/^(?:[\d ]+,\d{2}[A-Z0-9\/\.]+\t?)?\d+(?:\.\d+)?\s+(VNT|m)\s+\d+,\d{2}/iu', $line) === 1;
    }

    private function looksLikeSumCodeLine(string $line): bool
    {
        return preg_match('/^[\d ]+,\d{2}[A-Z0-9\/\.]/u', $line) === 1;
    }

    private function looksLikeTrailingCollection(string $text): bool
    {
        $token = strtoupper(preg_replace('/\s+/', '', $text) ?? $text);
        if ($token === '' || strlen($token) > 16) {
            return false;
        }

        if (preg_match('/^[A-Z]+$/', $token) !== 1) {
            return false;
        }

        foreach (['AGNONA', 'ARGANO', 'BORGA', 'CATANIA', 'DAKOTA', 'ERASER', 'FERRARA', 'LUGANO', 'MERINO', 'PALOMA', 'SARAMO'] as $known) {
            if ($token === $known || (strlen($token) >= 2 && str_starts_with($known, $token))) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeNameContinuation(string $name, string $next): bool
    {
        if ($this->titles->isLeather($name) && $this->titles->parseLeather($name) === null) {
            return preg_match('/^[A-Za-z0-9][A-Za-z0-9\s\-]{0,40}$/', $next) === 1;
        }

        if (preg_match('/^katalogas\b/iu', $name) === 1 && preg_match('/^[A-Za-zĄČĘĖĮŠŲŪŽąčęėįšųūž\s]{1,40}$/u', $next) === 1) {
            return true;
        }

        return false;
    }

    private function codeFromSumCode(string $sumCode): string
    {
        $sumCode = trim($sumCode);
        if ($sumCode === '') {
            return '';
        }

        if (preg_match('/^[\d ]+,\d{2}(.+)$/u', $sumCode, $matches) === 1) {
            return trim($matches[1]);
        }

        return $sumCode;
    }

    private function inferCodeFromName(string $name): ?string
    {
        $leather = $this->titles->parseLeather($name);
        if ($leather !== null) {
            return strtoupper($leather['collection'].$leather['color_code'].$leather['color_name'].$leather['size']);
        }

        if (preg_match('/^katalogas\s+(.+)$/iu', trim($name), $matches) === 1) {
            $collection = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $matches[1]) ?? $matches[1]);

            return $collection !== '' ? '/'.$collection.'/KATALOGAI' : null;
        }

        return null;
    }

    private function parseLtDecimal(string $value): float
    {
        return (float) str_replace([' ', ','], ['', '.'], trim($value));
    }

    public function normalizeCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $code = str_replace(['\\'], '/', $code);

        return preg_replace('/[^A-Z0-9\/]/', '', $code) ?? '';
    }
}
