<?php

namespace App\Services\BankStatements;

use App\Enums\BankStatementDirection;
use App\Exceptions\BankStatementXmlException;
use App\Models\CompanyBankAccount;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Throwable;

/**
 * Parses accounting cash-flow Excel exports (PLESJUD_01 / "Piniginių lėšų judėjimas").
 * All worksheets are scanned — one sheet per bank account is typical.
 */
class BankStatementExcelParser
{
    /**
     * @return array{
     *     message_id: ?string,
     *     statement_id: ?string,
     *     account_iban: ?string,
     *     bank_name: ?string,
     *     currency: ?string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     transactions: list<array{
     *         booking_date: string,
     *         value_date: ?string,
     *         direction: BankStatementDirection,
     *         amount: float,
     *         currency: string,
     *         account_iban: ?string,
     *         bank_name: ?string,
     *         counterpart_name: ?string,
     *         counterpart_iban: ?string,
     *         counterpart_bic: ?string,
     *         description: ?string,
     *         end_to_end_id: ?string,
     *         account_servicer_ref: ?string,
     *         transaction_id: ?string
     *     }>
     * }
     */
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            throw new BankStatementXmlException('Excel file was not found.');
        }

        try {
            $spreadsheet = IOFactory::load($path);
        } catch (Throwable $exception) {
            throw new BankStatementXmlException('Could not read the Excel bank statement: '.$exception->getMessage(), 0, $exception);
        }

        try {
            $transactions = [];
            $accountIbans = [];
            $bankName = null;
            $currency = 'EUR';
            $periodFrom = null;
            $periodTo = null;
            $statementId = null;

            foreach ($spreadsheet->getAllSheets() as $sheet) {
                $parsedSheet = $this->parseSheet($sheet);
                if ($parsedSheet === null) {
                    continue;
                }

                if ($bankName === null && filled($parsedSheet['bank_name'])) {
                    $bankName = $parsedSheet['bank_name'];
                }
                if ($statementId === null && filled($parsedSheet['account_code'])) {
                    $statementId = $parsedSheet['account_code'];
                }
                if (filled($parsedSheet['currency'])) {
                    $currency = $parsedSheet['currency'];
                }
                if ($parsedSheet['period_from'] !== null && ($periodFrom === null || $parsedSheet['period_from'] < $periodFrom)) {
                    $periodFrom = $parsedSheet['period_from'];
                }
                if ($parsedSheet['period_to'] !== null && ($periodTo === null || $parsedSheet['period_to'] > $periodTo)) {
                    $periodTo = $parsedSheet['period_to'];
                }
                if ($parsedSheet['account_iban'] !== null) {
                    $accountIbans[$parsedSheet['account_iban']] = true;
                }

                foreach ($parsedSheet['transactions'] as $row) {
                    $transactions[] = $row;
                }
            }

            if ($transactions === []) {
                throw new BankStatementXmlException('No income or outcome lines were found in the Excel bank statement.');
            }

            usort(
                $transactions,
                function (array $left, array $right): int {
                    $byDate = strcmp($left['booking_date'], $right['booking_date']);
                    if ($byDate !== 0) {
                        return $byDate;
                    }

                    return strcmp((string) ($left['account_iban'] ?? ''), (string) ($right['account_iban'] ?? ''));
                },
            );

            $dates = array_column($transactions, 'booking_date');
            $ibanList = array_keys($accountIbans);

            return [
                'message_id' => null,
                'statement_id' => $statementId,
                'account_iban' => $ibanList[0] ?? null,
                'bank_name' => $bankName,
                'currency' => $currency,
                'period_from' => $periodFrom ?? min($dates),
                'period_to' => $periodTo ?? max($dates),
                'transactions' => $transactions,
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        }
    }

    /**
     * @return array{
     *     account_code: ?string,
     *     bank_name: ?string,
     *     account_iban: ?string,
     *     currency: ?string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     transactions: list<array<string, mixed>>
     * }|null
     */
    private function parseSheet(Worksheet $sheet): ?array
    {
        $highestRow = (int) $sheet->getHighestDataRow();
        if ($highestRow < 1) {
            return null;
        }

        $account = $this->findAccountMeta($sheet, $highestRow);
        $period = $this->findPeriod($sheet, $highestRow);
        $currency = $account['currency'] ?? 'EUR';
        $transactions = [];
        $currentDate = null;

        for ($row = 1; $row <= $highestRow; $row++) {
            $operation = $this->cellString($sheet, 'B', $row);

            if ($this->isDateLabel($operation)) {
                $currentDate = $operation;

                continue;
            }

            $direction = $this->directionFromOperation($operation);
            if ($direction === null || $currentDate === null) {
                continue;
            }

            $debit = $this->cellAmount($sheet, 'L', $row);
            $credit = $this->cellAmount($sheet, 'P', $row);
            $amount = $direction === BankStatementDirection::Credit ? $debit : $credit;

            if ($amount === null || $amount <= 0) {
                continue;
            }

            $counterpartRaw = $this->cellString($sheet, 'G', $row);
            $document = $this->cellString($sheet, 'F', $row);
            $opNumber = $this->cellString($sheet, 'E', $row);
            $counterpart = BankStatementCounterpartResolver::resolve(
                $counterpartRaw !== '' ? $counterpartRaw : null,
                $document !== '' ? $document : null,
                $document !== '' ? $document : null,
            ) ?? ($counterpartRaw !== '' ? $counterpartRaw : null);

            $descriptionSource = $document;
            $remittance = BankStatementCounterpartCleaner::extractTrailingRemittance(
                $counterpartRaw !== '' ? $counterpartRaw : null,
            );
            if (! filled($remittance) && $counterpartRaw !== ''
                && BankStatementDescriptionCleaner::isPurposeRemittanceCell($counterpartRaw)) {
                $remittance = $counterpartRaw;
            }
            if (filled($remittance)) {
                $alreadyHas = filled($document)
                    && str_contains(mb_strtolower($document), mb_strtolower($remittance));
                if (! $alreadyHas) {
                    $descriptionSource = $document !== ''
                        ? $document.' · '.$remittance
                        : $remittance;
                }
            }

            $description = BankStatementDescriptionCleaner::clean(
                $descriptionSource !== '' ? $descriptionSource : null,
                $counterpart,
            );

            $transactions[] = [
                'booking_date' => $currentDate,
                'value_date' => $currentDate,
                'direction' => $direction,
                'amount' => round($amount, 2),
                'currency' => $currency,
                'account_iban' => $account['account_iban'],
                'bank_name' => $account['bank_name'],
                'counterpart_name' => $counterpart,
                'counterpart_iban' => null,
                'counterpart_bic' => null,
                'description' => $description !== '' ? $description : null,
                'end_to_end_id' => $document !== '' ? $document : null,
                'account_servicer_ref' => $opNumber !== '' ? $opNumber : null,
                'transaction_id' => $document !== '' ? $document : ($opNumber !== '' ? $opNumber : null),
            ];
        }

        if ($transactions === []) {
            return null;
        }

        return [
            'account_code' => $account['account_code'],
            'bank_name' => $account['bank_name'],
            'account_iban' => $account['account_iban'],
            'currency' => $currency,
            'period_from' => $period['from'],
            'period_to' => $period['to'],
            'transactions' => $transactions,
        ];
    }

    /**
     * @return array{account_code: ?string, bank_name: ?string, account_iban: ?string, currency: ?string}
     */
    private function findAccountMeta(Worksheet $sheet, int $highestRow): array
    {
        for ($row = 1; $row <= min($highestRow, 40); $row++) {
            $value = $this->cellString($sheet, 'A', $row);
            if ($value === '') {
                continue;
            }

            if (preg_match('/^(\d+)\s*-\s*(.+?)\s*\((\d+)\)\s*([A-Z]{3})\s*$/u', $value, $matches) !== 1) {
                continue;
            }

            $accountCode = $matches[1];
            $bankName = trim($matches[2]);
            $accountTail = $matches[3];
            $currency = strtoupper($matches[4]);
            $excelKey = 'ACC-'.$accountCode.'-'.$accountTail;

            return [
                'account_code' => $accountCode,
                'bank_name' => $bankName !== '' ? $bankName : null,
                'account_iban' => CompanyBankAccount::canonicalizeStatementAccount($excelKey) ?? $excelKey,
                'currency' => $currency,
            ];
        }

        return [
            'account_code' => null,
            'bank_name' => null,
            'account_iban' => null,
            'currency' => 'EUR',
        ];
    }

    /**
     * @return array{from: ?string, to: ?string}
     */
    private function findPeriod(Worksheet $sheet, int $highestRow): array
    {
        for ($row = 1; $row <= min($highestRow, 40); $row++) {
            for ($column = 1; $column <= 20; $column++) {
                $value = $this->cellString($sheet, Coordinate::stringFromColumnIndex($column), $row);
                if ($value === '') {
                    continue;
                }

                if (preg_match('/Data\s*>=\s*(\d{4}-\d{2}-\d{2}).*Data\s*<=\s*(\d{4}-\d{2}-\d{2})/iu', $value, $matches) === 1) {
                    return [
                        'from' => $this->normalizeDate($matches[1]),
                        'to' => $this->normalizeDate($matches[2]),
                    ];
                }

                if (preg_match('/\((\d{4}-\d{2}-\d{2})\s*-\s*(\d{4}-\d{2}-\d{2})\)/u', $value, $matches) === 1) {
                    return [
                        'from' => $this->normalizeDate($matches[1]),
                        'to' => $this->normalizeDate($matches[2]),
                    ];
                }
            }
        }

        return ['from' => null, 'to' => null];
    }

    private function directionFromOperation(string $operation): ?BankStatementDirection
    {
        $normalized = mb_strtolower(trim($operation));
        $normalized = strtr($normalized, [
            'į' => 'i',
            'š' => 's',
            'ų' => 'u',
        ]);

        return match (true) {
            str_contains($normalized, 'iplauk') => BankStatementDirection::Credit,
            str_contains($normalized, 'ismok') => BankStatementDirection::Debit,
            default => null,
        };
    }

    private function isDateLabel(string $value): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    private function normalizeDate(string $value): ?string
    {
        try {
            return Carbon::parse($value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }

    private function cellString(Worksheet $sheet, string $column, int $row): string
    {
        $value = $sheet->getCell($column.$row)->getFormattedValue();

        return trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');
    }

    private function cellAmount(Worksheet $sheet, string $column, int $row): ?float
    {
        $raw = $sheet->getCell($column.$row)->getCalculatedValue();

        if (is_numeric($raw)) {
            return round((float) $raw, 2);
        }

        $formatted = $this->cellString($sheet, $column, $row);
        if ($formatted === '') {
            return null;
        }

        $normalized = str_replace([' ', ','], ['', '.'], $formatted);
        if (! is_numeric($normalized)) {
            return null;
        }

        return round((float) $normalized, 2);
    }
}
