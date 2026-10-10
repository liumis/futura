<?php

namespace App\Services;

use App\Enums\EmployeeMonthlyPaymentStatus;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

class DuPayrollJournalExcelImporter
{
    /**
     * @return array{
     *     year: int,
     *     month: int,
     *     created: int,
     *     updated: int,
     *     payments: Collection<int, EmployeeMonthlyPayment>,
     *     unmatched: list<string>
     * }
     */
    public static function import(string $absolutePath, ?Carbon $paymentDate = null): array
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException('File not found: '.$absolutePath);
        }

        $spreadsheet = IOFactory::load($absolutePath);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, true, true);

        [$year, $month] = self::detectPeriod($absolutePath, $sheet->getTitle(), $rows);
        $cols = self::detectColumns($rows);
        $paymentDate ??= Carbon::create($year, $month, 1)->endOfMonth();

        $employees = Employee::query()->get();
        $created = 0;
        $updated = 0;
        $unmatched = [];
        $payments = collect();

        foreach ($rows as $index => $row) {
            if (! is_array($row) || $index < 10) {
                continue;
            }

            $name = trim((string) ($row['I'] ?? ''));
            if ($name === '' || Str::lower($name) === 'iš viso' || ! preg_match('/\p{L}/u', $name)) {
                continue;
            }

            // Data rows start with an ordinal in column A.
            $ordinal = trim((string) ($row['A'] ?? ''));
            if ($ordinal === '' || ! is_numeric($ordinal)) {
                continue;
            }

            $employee = self::matchEmployee($name, $employees);
            if ($employee === null) {
                $unmatched[] = $name;

                continue;
            }

            $base = self::number($row[$cols['base']] ?? null);
            $gross = self::number($row[$cols['gross']] ?? null);
            $npd = self::number($row[$cols['npd']] ?? null);
            $gpm = self::number($row[$cols['gpm']] ?? null);
            $vsd = self::number($row[$cols['vsd']] ?? null);
            $psd = self::number($row[$cols['psd']] ?? null);
            $pillar = self::number($row[$cols['pillar']] ?? null);
            $sodraEmployer = self::number($row[$cols['sodra_employer']] ?? null);
            $netPrimary = self::number($row[$cols['net']] ?? null);
            $netAlt = self::number($row[$cols['net_alt']] ?? null);
            // Some months put net under AA, others under AB (or leave AA empty when AB has the payout).
            $net = $netPrimary > 0.005 ? $netPrimary : $netAlt;
            $bonus = self::number($row[$cols['bonus']] ?? null);
            $premiaExtra = max(0, round($gross - $base, 2));

            if ($gross < 0.005 && $net < 0.005) {
                continue;
            }

            if ($bonus < 0.005 && $premiaExtra > 0.005) {
                $bonus = $premiaExtra;
            }

            $sodraEmployee = round($vsd + $psd + $pillar, 2);

            $payload = [
                'employee_id' => $employee->getKey(),
                'payment_date' => $paymentDate->toDateString(),
                'base_salary' => $base > 0 ? $base : $gross,
                'bonus_payment' => $bonus > 0 ? $bonus : null,
                'gross_amount' => $gross,
                'npd_amount' => $npd,
                'sodra_employee_amount' => $sodraEmployee,
                'sodra_employer_amount' => $sodraEmployer,
                'gpm_amount' => $gpm,
                'net_amount' => $net,
                'comment' => 'Imported from DU journal '.basename($absolutePath),
                'status' => EmployeeMonthlyPaymentStatus::Payed,
                'is_paid' => true,
                'paid_at' => $paymentDate->copy()->endOfDay(),
            ];

            $existing = EmployeeMonthlyPayment::query()
                ->where('employee_id', $employee->getKey())
                ->whereDate('payment_date', $paymentDate->toDateString())
                ->first();

            if ($existing instanceof EmployeeMonthlyPayment) {
                $existing->fill($payload)->save();
                $payments->push($existing->fresh(['employee']));
                $updated++;
            } else {
                $payment = EmployeeMonthlyPayment::query()->create($payload);
                $payments->push($payment->load('employee'));
                $created++;
            }
        }

        return [
            'year' => $year,
            'month' => $month,
            'created' => $created,
            'updated' => $updated,
            'payments' => $payments,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * April-style journals put GPM in U and net in AB; May+ use T / AA.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{
     *     base: string,
     *     gross: string,
     *     npd: string,
     *     gpm: string,
     *     vsd: string,
     *     psd: string,
     *     pillar: string,
     *     sodra_employer: string,
     *     net: string,
     *     net_alt: string,
     *     bonus: string
     * }
     */
    private static function detectColumns(array $rows): array
    {
        $defaults = [
            'base' => 'F',
            'gross' => 'R',
            'npd' => 'G',
            'gpm' => 'T',
            'vsd' => 'U',
            'psd' => 'V',
            'pillar' => 'W',
            'sodra_employer' => 'S',
            'net' => 'AA',
            'net_alt' => 'AB',
            'bonus' => 'O',
        ];

        $gpmCol = null;
        $netCol = null;

        foreach (array_slice($rows, 0, 12, true) as $row) {
            if (! is_array($row)) {
                continue;
            }
            foreach ($row as $col => $value) {
                $text = Str::lower(trim(preg_replace('/\s+/u', ' ', (string) $value) ?? ''));
                if ($text === '') {
                    continue;
                }
                if ($gpmCol === null && (str_contains($text, 'gyventojų pajamų mokestis') || $text === 'gpm')) {
                    $gpmCol = (string) $col;
                }
                if ($netCol === null && str_contains($text, 'išmokėti')) {
                    $netCol = (string) $col;
                }
            }
        }

        if ($gpmCol !== null) {
            $defaults['gpm'] = $gpmCol;
            // Older layout (Apr): GPM=U, V/W = employee VSD/PSD, S = employer, net=AB.
            if ($gpmCol === 'U') {
                $defaults['vsd'] = 'V';
                $defaults['psd'] = 'W';
                $defaults['pillar'] = 'X';
                $defaults['sodra_employer'] = 'S';
                $defaults['net'] = $netCol ?? 'AB';
                $defaults['net_alt'] = 'AA';
            } else {
                // May+ layout: GPM=T, U/V = VSD/PSD, W = pillar II, S = employer, net=AA.
                $defaults['vsd'] = 'U';
                $defaults['psd'] = 'V';
                $defaults['pillar'] = 'W';
                $defaults['sodra_employer'] = 'S';
                if ($netCol !== null) {
                    $defaults['net'] = $netCol;
                    $defaults['net_alt'] = $netCol === 'AA' ? 'AB' : 'AA';
                }
            }
        }

        return $defaults;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{0: int, 1: int}
     */
    private static function detectPeriod(string $path, string $sheetTitle, array $rows): array
    {
        // Filename wins: August workbooks are often saved from a July template
        // still titled "2026-07" while the header/filename say 08 / rugpjūčio.
        if (preg_match('/DU\s+(20\d{2})\s+(\d{2})/i', basename($path), $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        $blob = '';
        foreach (array_slice($rows, 0, 8, true) as $row) {
            $blob .= ' '.implode(' ', array_map(fn ($v) => (string) $v, $row));
        }

        if (preg_match('/(20\d{2}).{0,20}(sausio|vasario|kovo|balandžio|gegužės|birželio|liepos|rugpjūčio|rugsėjo|spalio|lapkričio|gruodžio)/iu', $blob, $m) === 1) {
            $monthMap = [
                'sausio' => 1, 'vasario' => 2, 'kovo' => 3, 'balandžio' => 4, 'gegužės' => 5,
                'birželio' => 6, 'liepos' => 7, 'rugpjūčio' => 8, 'rugsėjo' => 9,
                'spalio' => 10, 'lapkričio' => 11, 'gruodžio' => 12,
            ];
            $key = Str::lower($m[2]);

            return [(int) $m[1], $monthMap[$key] ?? (int) now()->month];
        }

        if (preg_match('/(20\d{2})[^\d]?(\d{2})/', $sheetTitle, $m) === 1) {
            return [(int) $m[1], (int) $m[2]];
        }

        throw new RuntimeException('Could not detect year/month from '.$path);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    private static function matchEmployee(string $fullName, Collection $employees): ?Employee
    {
        $needle = self::normalizeName($fullName);

        foreach ($employees as $employee) {
            if (self::normalizeName($employee->fullName()) === $needle) {
                return $employee;
            }
        }

        foreach ($employees as $employee) {
            $reversed = self::normalizeName($employee->surname.' '.$employee->name);
            if ($reversed === $needle) {
                return $employee;
            }
        }

        return null;
    }

    private static function normalizeName(string $name): string
    {
        $name = Str::upper(trim(preg_replace('/\s+/u', ' ', $name) ?? ''));
        $map = ['Ą' => 'A', 'Č' => 'C', 'Ę' => 'E', 'Ė' => 'E', 'Į' => 'I', 'Š' => 'S', 'Ų' => 'U', 'Ū' => 'U', 'Ž' => 'Z'];

        return strtr($name, $map);
    }

    private static function number(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $raw = trim((string) $value);
        $raw = str_replace([' ', "\xc2\xa0"], '', $raw);
        if (str_contains($raw, ',') && str_contains($raw, '.')) {
            $raw = str_replace(',', '', $raw);
        } else {
            $raw = str_replace(',', '.', $raw);
        }

        return is_numeric($raw) ? round((float) $raw, 2) : 0.0;
    }
}
