<?php

namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\SharepointSetting;
use App\Models\WorkSchedule;
use App\Support\TimesheetCode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

/**
 * Import historical Lithuanian monthly timesheets (Tabelis / darbo laiko žiniaraštis)
 * into WorkSchedule entries and store the source file on SharePoint as a Document.
 */
class TabelisTimesheetImporter
{
    public const DOCUMENT_TYPE_NAME = 'Darbo laiko apskaitos žiniaraštis';

    /**
     * @return array{
     *     year: int,
     *     month: int,
     *     employees: list<array{employee_id: int, name: string, entries: int, actual_hours: float, planned_hours: float}>,
     *     unmatched: list<string>,
     *     document_id: int|null,
     * }
     */
    public static function import(string $path, bool $storeDocument = true): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('File not found: '.$path);
        }

        [$year, $month] = self::yearMonthFromFilename($path);
        [$parsed, $unmatched] = self::parseFile($path, $year, $month);

        if ($parsed === []) {
            throw new RuntimeException('No employee day rows found in '.basename($path));
        }

        $employeeResults = [];

        DB::transaction(function () use ($parsed, $year, $month, &$employeeResults): void {
            foreach ($parsed as $row) {
                /** @var Employee $employee */
                $employee = $row['employee'];
                $schedule = WorkSchedule::query()->firstOrCreate([
                    'employee_id' => $employee->getKey(),
                    'year' => $year,
                    'month' => $month,
                ]);

                $schedule->entries()->delete();
                $schedule->syncEntriesFromForm($row['entries']);

                $employeeResults[] = [
                    'employee_id' => (int) $employee->getKey(),
                    'name' => $employee->fullName(),
                    'entries' => count($row['entries']),
                    'actual_hours' => round(collect($row['entries'])->sum(fn (array $e): float => (float) $e['actual_hours']), 2),
                    'planned_hours' => round(collect($row['entries'])->sum(fn (array $e): float => (float) $e['hours']), 2),
                ];
            }
        });

        $documentId = null;
        if ($storeDocument) {
            $documentId = self::storeSourceDocument($path, $year, $month)?->getKey();
        }

        return [
            'year' => $year,
            'month' => $month,
            'employees' => $employeeResults,
            'unmatched' => $unmatched,
            'document_id' => $documentId !== null ? (int) $documentId : null,
        ];
    }

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function yearMonthFromFilename(string $path): array
    {
        $base = basename($path);
        if (preg_match('/Tabelis\s+(\d{4})\s+(\d{1,2})/i', $base, $m) !== 1) {
            throw new RuntimeException('Cannot parse year/month from filename: '.$base);
        }

        return [(int) $m[1], (int) $m[2]];
    }

    /**
     * @return array{
     *     0: list<array{employee: Employee, entries: list<array{work_date: string, hours: float, actual_hours: float, is_not_working_day: bool}>}>,
     *     1: list<string>
     * }
     */
    public static function parseFile(string $path, int $year, int $month): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);

        $highestRow = (int) $sheet->getHighestDataRow();
        $highestCol = Coordinate::columnIndexFromString($sheet->getHighestDataColumn());

        $grid = [];
        for ($r = 1; $r <= $highestRow; $r++) {
            for ($c = 1; $c <= $highestCol; $c++) {
                $coord = Coordinate::stringFromColumnIndex($c).$r;
                $value = $sheet->getCell($coord)->getCalculatedValue();
                $grid[$r][$c] = is_scalar($value) ? trim((string) $value) : '';
            }
        }

        $dayColumns = self::detectDayColumns($grid, $year, $month);
        if ($dayColumns === []) {
            throw new RuntimeException('Could not detect day columns in '.basename($path));
        }

        $employees = Employee::query()->orderBy('id')->get();
        $nameRows = self::collectEmployeeNameRows($grid, $employees);
        $found = [];
        $unmatched = [];

        foreach ($nameRows as $nameRow) {
            if ($nameRow['employee'] === null) {
                $unmatched[] = $nameRow['name'];

                continue;
            }

            /** @var Employee $employee */
            $employee = $nameRow['employee'];
            $dayValues = self::dayValuesForEmployeeRow($grid, $nameRow['row'], $dayColumns);
            if ($dayValues === []) {
                continue;
            }

            $defaultHours = self::typicalDayHours($dayValues, WorkScheduleGenerator::defaultHoursFor($employee));
            $entries = self::buildMonthEntries($year, $month, $dayValues, $defaultHours);

            $found[$employee->getKey()] = [
                'employee' => $employee,
                'entries' => $entries,
            ];
        }

        return [array_values($found), array_values(array_unique($unmatched))];
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return array<int, int> columnIndex => dayOfMonth
     */
    protected static function detectDayColumns(array $grid, int $year, int $month): array
    {
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;

        // 1) Excel serial date headers (Jun+ templates).
        foreach ($grid as $cells) {
            $map = [];
            foreach ($cells as $col => $raw) {
                if ($raw === '' || ! is_numeric($raw)) {
                    continue;
                }
                $num = (float) $raw;
                if ($num < 40000 || $num > 60000) {
                    continue;
                }
                try {
                    $date = Carbon::instance(ExcelDate::excelToDateTimeObject($num));
                } catch (Throwable) {
                    continue;
                }
                if ((int) $date->format('Y') === $year && (int) $date->format('n') === $month) {
                    $map[$col] = (int) $date->format('j');
                }
            }
            if (count(array_unique(array_values($map))) >= max(20, $daysInMonth - 2)) {
                ksort($map);

                return $map;
            }
        }

        // 2) Merge integer day headers across consecutive rows (Apr layout: 1–28 then 29–30).
        // Prefer the leftmost column for each day so summary cells like AQ:3 do not collide.
        $rowKeys = array_keys($grid);
        sort($rowKeys);
        $best = [];
        foreach ($rowKeys as $rowIndex) {
            $dayToCol = [];
            foreach ([$rowIndex, $rowIndex + 1] as $r) {
                if (! isset($grid[$r])) {
                    continue;
                }
                foreach ($grid[$r] as $col => $raw) {
                    if ($raw === '' || ! is_numeric($raw)) {
                        continue;
                    }
                    $num = (float) $raw;
                    if ($num < 1 || $num > $daysInMonth || abs($num - (int) $num) >= 0.00001) {
                        continue;
                    }
                    $day = (int) $num;
                    if (! isset($dayToCol[$day]) || $col < $dayToCol[$day]) {
                        $dayToCol[$day] = $col;
                    }
                }
            }

            if (count($dayToCol) < max(20, $daysInMonth - 2)) {
                continue;
            }

            $merged = [];
            foreach ($dayToCol as $day => $col) {
                $merged[$col] = $day;
            }
            ksort($merged);

            // Require a contiguous day sequence in column order (rejects May's broken headers).
            $orderedDays = array_values($merged);
            $expected = range((int) $orderedDays[0], (int) $orderedDays[0] + count($orderedDays) - 1);
            if ($orderedDays !== $expected || (int) $orderedDays[0] !== 1) {
                continue;
            }

            if (count($merged) > count($best)) {
                $best = $merged;
            }
        }

        if ($best !== []) {
            return $best;
        }

        // 3) Fallback: contiguous day-value block after the name column (May broken headers).
        return self::inferDayColumnsFromData($grid, $daysInMonth);
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @return array<int, int>
     */
    protected static function inferDayColumnsFromData(array $grid, int $daysInMonth): array
    {
        $scoreByStart = [];

        foreach ($grid as $cells) {
            $nameCol = null;
            foreach ($cells as $col => $value) {
                if (self::looksLikePersonName($value)) {
                    $nameCol = $col;
                    break;
                }
            }
            if ($nameCol === null) {
                continue;
            }

            // Skip job-title / empty columns after the name, then require a dense day block.
            $start = $nameCol + 1;
            $limit = $nameCol + 12;
            while ($start <= $limit && (($cells[$start] ?? '') === '' || self::looksLikeJobTitle((string) ($cells[$start] ?? '')))) {
                $start++;
            }

            $filled = 0;
            for ($i = 0; $i < $daysInMonth; $i++) {
                $raw = $cells[$start + $i] ?? '';
                if ($raw !== '' && self::looksLikeDayCell($raw)) {
                    $filled++;
                }
            }

            // Prefer full-month rows (Deividas/Žaneta) over mid-month starters when voting.
            if ($filled >= 15) {
                $scoreByStart[$start] = ($scoreByStart[$start] ?? 0) + $filled;
            }
        }

        if ($scoreByStart === []) {
            return [];
        }

        arsort($scoreByStart);
        $startCol = (int) array_key_first($scoreByStart);
        $map = [];
        for ($day = 1; $day <= $daysInMonth; $day++) {
            $map[$startCol + $day - 1] = $day;
        }

        return $map;
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @param  Collection<int, Employee>  $employees
     * @return list<array{row: int, name: string, employee: ?Employee}>
     */
    protected static function collectEmployeeNameRows(array $grid, Collection $employees): array
    {
        $rows = [];
        $skipRows = [];

        $rowKeys = array_keys($grid);
        sort($rowKeys);

        foreach ($rowKeys as $rowIndex) {
            if (isset($skipRows[$rowIndex])) {
                continue;
            }

            $cells = $grid[$rowIndex];
            $name = self::rowEmployeeName($cells);
            if ($name === null) {
                continue;
            }

            // April layout: "Deividas" then next row "Arman".
            $parts = preg_split('/\s+/', $name) ?: [];
            if (count($parts) === 1 && isset($grid[$rowIndex + 1])) {
                $nextName = self::rowEmployeeName($grid[$rowIndex + 1]);
                if ($nextName !== null) {
                    $nextParts = preg_split('/\s+/', $nextName) ?: [];
                    if (count($nextParts) === 1) {
                        $combined = $name.' '.$nextName;
                        if (self::matchEmployee($employees, $combined) !== null) {
                            $name = $combined;
                            $skipRows[$rowIndex + 1] = true;
                        }
                    }
                }
            }

            $employee = self::matchEmployee($employees, $name);
            // Ignore header/label noise that does not match an employee.
            if ($employee === null) {
                continue;
            }

            $rows[] = [
                'row' => $rowIndex,
                'name' => $name,
                'employee' => $employee,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<int, string>  $cells
     */
    protected static function rowEmployeeName(array $cells): ?string
    {
        foreach ($cells as $value) {
            if (! self::looksLikePersonName($value)) {
                continue;
            }

            return preg_replace('/\s+/', ' ', $value) ?? $value;
        }

        return null;
    }

    protected static function looksLikePersonName(string $value): bool
    {
        if ($value === '' || is_numeric($value) || mb_strlen($value) < 3) {
            return false;
        }
        if (preg_match('/\d/', $value) === 1) {
            return false;
        }

        $lower = mb_strtolower($value);
        if (str_contains($lower, 'vardas')
            || str_contains($lower, 'žiniarašt')
            || str_contains($lower, 'ziniarast')
            || str_contains($lower, 'direktorius deividas')
            || str_contains($lower, 'užpild')
            || str_contains($lower, 'uzpild')
            || str_contains($lower, 'tvirtina')
            || str_contains($lower, 'darbdavys')
            || str_contains($lower, 'tarnybin')
            || str_contains($lower, 'futura')
            || str_contains($lower, 'iš viso')
            || str_contains($lower, 'is viso')
        ) {
            return false;
        }

        if (self::looksLikeJobTitle($value)) {
            return false;
        }

        return preg_match('/^[A-Za-zĄČĘĖĮŠŲŪŽąčęėįšųūž .\\-]+$/u', $value) === 1;
    }

    protected static function looksLikeJobTitle(string $value): bool
    {
        return preg_match('/^(direktorius|vyr\.?\s*buhalter|vadybinink)/iu', trim($value)) === 1;
    }

    protected static function looksLikeDayCell(string $raw): bool
    {
        if (is_numeric($raw)) {
            return true;
        }

        $code = strtoupper(trim($raw));

        return TimesheetCode::isValid($code) || in_array($code, ['P', 'S', 'A', 'K', 'M', 'L'], true);
    }

    /**
     * @param  Collection<int, Employee>  $employees
     */
    protected static function matchEmployee(Collection $employees, string $name): ?Employee
    {
        $needle = self::normalizePersonName($name);
        if ($needle === '') {
            return null;
        }

        foreach ($employees as $employee) {
            $full = self::normalizePersonName($employee->fullName());
            if ($full === $needle) {
                return $employee;
            }
        }

        foreach ($employees as $employee) {
            $first = self::normalizePersonName((string) $employee->name);
            $last = self::normalizePersonName((string) $employee->surname);
            if ($first !== '' && $last !== '' && str_contains($needle, $first) && str_contains($needle, $last)) {
                return $employee;
            }
        }

        // Only allow first-name-only match when unique among employees.
        $firstHits = [];
        foreach ($employees as $employee) {
            $first = self::normalizePersonName((string) $employee->name);
            if ($first !== '' && $needle === $first) {
                $firstHits[] = $employee;
            }
        }
        if (count($firstHits) === 1) {
            return $firstHits[0];
        }

        return null;
    }

    protected static function normalizePersonName(string $name): string
    {
        $name = Str::lower(Str::ascii(trim($name)));
        $name = preg_replace('/[^a-z\s]/', '', $name) ?? '';

        return trim(preg_replace('/\s+/', ' ', $name) ?? '');
    }

    /**
     * @param  array<int, array<int, string>>  $grid
     * @param  array<int, int>  $dayColumns
     * @return array<int, string> dayOfMonth => raw cell
     */
    protected static function dayValuesForEmployeeRow(array $grid, int $rowIndex, array $dayColumns): array
    {
        $candidates = [];

        foreach ([$rowIndex, $rowIndex - 1, $rowIndex - 2, $rowIndex + 1] as $r) {
            if (! isset($grid[$r])) {
                continue;
            }
            $values = [];
            $filled = 0;
            $numericOrCode = 0;
            foreach ($dayColumns as $col => $day) {
                $raw = $grid[$r][$col] ?? '';
                $values[$day] = $raw;
                if ($raw !== '') {
                    $filled++;
                    if (self::looksLikeDayCell($raw)) {
                        $numericOrCode++;
                    }
                }
            }
            // Prefer the hours row (numbers/codes), not annotation rows (only K/PL/SE).
            if ($numericOrCode >= 5) {
                $candidates[] = [
                    'filled' => $filled,
                    'score' => $numericOrCode * 10 + $filled,
                    'values' => $values,
                    'row' => $r,
                ];
            }
        }

        if ($candidates === []) {
            return [];
        }

        usort($candidates, function (array $a, array $b) use ($rowIndex): int {
            $cmp = $b['score'] <=> $a['score'];
            if ($cmp !== 0) {
                return $cmp;
            }

            // Prefer same row as name, then row above (April hours-above-name).
            $da = abs($a['row'] - $rowIndex);
            $db = abs($b['row'] - $rowIndex);

            return $da <=> $db;
        });

        return $candidates[0]['values'];
    }

    /**
     * Prefer the most common positive numeric day length from the timesheet row
     * (e.g. Kristina works 2 h/day in the sheet while employee % implies 4 h).
     *
     * @param  array<int, string>  $dayValues
     */
    protected static function typicalDayHours(array $dayValues, float $fallback): float
    {
        $counts = [];
        foreach ($dayValues as $raw) {
            if ($raw === '' || ! is_numeric($raw)) {
                continue;
            }
            $hours = round((float) $raw, 2);
            if ($hours <= 0) {
                continue;
            }
            $key = number_format($hours, 2, '.', '');
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }

        if ($counts === []) {
            return $fallback;
        }

        arsort($counts);

        return (float) array_key_first($counts);
    }

    /**
     * @param  array<int, string>  $dayValues
     * @return list<array{work_date: string, hours: float, actual_hours: float, is_not_working_day: bool}>
     */
    protected static function buildMonthEntries(
        int $year,
        int $month,
        array $dayValues,
        float $defaultHours,
    ): array {
        $daysInMonth = Carbon::create($year, $month, 1)->daysInMonth;
        $entries = [];

        for ($day = 1; $day <= $daysInMonth; $day++) {
            $date = Carbon::create($year, $month, $day)->toDateString();
            $raw = trim((string) ($dayValues[$day] ?? ''));
            $entries[] = self::mapCellToEntry($date, $raw, $defaultHours);
        }

        return $entries;
    }

    /**
     * @return array{work_date: string, hours: float, actual_hours: float, is_not_working_day: bool}
     */
    protected static function mapCellToEntry(string $date, string $raw, float $defaultHours): array
    {
        if ($raw === '') {
            return [
                'work_date' => $date,
                'hours' => 0.0,
                'actual_hours' => 0.0,
                'is_not_working_day' => true,
            ];
        }

        if (is_numeric($raw)) {
            $hours = round((float) $raw, 2);

            return [
                'work_date' => $date,
                'hours' => $hours,
                'actual_hours' => $hours,
                'is_not_working_day' => $hours <= 0,
            ];
        }

        $code = strtoupper(trim($raw));
        if (preg_match('/^[A-Z]{1,3}$/', $code) !== 1) {
            $code = TimesheetCode::normalize($raw) ?? $code;
        }

        // Destination / annotation markers under business-trip overlays — ignore if alone.
        if (in_array($code, ['PL', 'SE', 'ES', 'DE'], true)) {
            return [
                'work_date' => $date,
                'hours' => $defaultHours,
                'actual_hours' => $defaultHours,
                'is_not_working_day' => false,
            ];
        }

        if (in_array($code, ['P', 'S'], true)) {
            return [
                'work_date' => $date,
                'hours' => 0.0,
                'actual_hours' => 0.0,
                'is_not_working_day' => true,
            ];
        }

        // Business trip counts as worked time.
        if ($code === 'K' || $code === 'KK') {
            return [
                'work_date' => $date,
                'hours' => $defaultHours,
                'actual_hours' => $defaultHours,
                'is_not_working_day' => false,
            ];
        }

        // Absences: keep planned hours, zero actual.
        if (TimesheetCode::isValid($code)) {
            return [
                'work_date' => $date,
                'hours' => $defaultHours,
                'actual_hours' => 0.0,
                'is_not_working_day' => false,
            ];
        }

        return [
            'work_date' => $date,
            'hours' => 0.0,
            'actual_hours' => 0.0,
            'is_not_working_day' => true,
        ];
    }

    protected static function storeSourceDocument(string $path, int $year, int $month): ?Document
    {
        $binary = file_get_contents($path);
        if ($binary === false || $binary === '') {
            throw new RuntimeException('Could not read timesheet file: '.$path);
        }

        if (! SharepointSetting::instance()->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured.');
        }

        $type = self::ensureDocumentTypeExists();
        $baseName = basename($path);
        $period = sprintf('%04d-%02d', $year, $month);
        $documentName = 'Darbo laiko apskaitos žiniaraštis — '.$period;

        $existing = Document::query()
            ->where('document_type_id', $type->getKey())
            ->where('name', $documentName)
            ->first();

        $document = $existing ?? Document::query()->create([
            'document_date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => auth()->id(),
            'flag_approved' => true,
            'user_approved_id' => auth()->id(),
            'approval_date' => now(),
            'content_hash' => hash('sha256', $binary),
        ]);

        if ($existing) {
            $document->forceFill([
                'content_hash' => hash('sha256', $binary),
                'document_date' => Carbon::create($year, $month, 1)->endOfMonth()->toDateString(),
            ])->save();
        }

        DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $binary, $baseName);

        return $document->fresh();
    }
}
