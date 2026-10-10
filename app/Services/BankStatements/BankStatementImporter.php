<?php

namespace App\Services\BankStatements;

use App\Enums\BankStatementDirection;
use App\Exceptions\BankStatementDuplicateException;
use App\Exceptions\BankStatementXmlException;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\CompanyBankAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BankStatementImporter
{
    public function __construct(
        private readonly BankStatementXmlParser $xmlParser,
        private readonly BankStatementExcelParser $excelParser,
        private readonly BankStatementPdfParser $pdfParser,
    ) {}

    /**
     * @return array{import: BankStatementImport, imported: int, skipped: int, total: int}
     */
    public function importFile(
        string $absolutePath,
        string $originalFilename,
        ?string $storedPath = null,
        ?int $uploadedBy = null,
        ?string $comment = null,
    ): array {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));

        if (in_array($extension, ['xls', 'xlsx', 'xlsm'], true)) {
            $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;
            $this->guardAgainstDuplicateFile($hash);

            return $this->importExcel($absolutePath, $originalFilename, $storedPath, $uploadedBy, $comment, $hash);
        }

        if ($extension === 'pdf') {
            $hash = is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;
            $this->guardAgainstDuplicateFile($hash);

            return $this->importPdf($absolutePath, $originalFilename, $storedPath, $uploadedBy, $comment, $hash);
        }

        $contents = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : '';
        $hash = $contents === '' ? null : hash('sha256', $contents);
        $this->guardAgainstDuplicateFile($hash);

        return $this->importXml($contents, $originalFilename, $storedPath, $uploadedBy, $comment, $hash);
    }

    /**
     * @return array{import: BankStatementImport, imported: int, skipped: int, total: int}
     */
    public function importPdf(
        string $absolutePath,
        string $originalFilename,
        ?string $storedPath = null,
        ?int $uploadedBy = null,
        ?string $comment = null,
        ?string $contentHash = null,
    ): array {
        $contentHash ??= is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;
        $this->guardAgainstDuplicateFile($contentHash);

        $parsed = $this->pdfParser->parse($absolutePath);

        return $this->storeParsed($parsed, $originalFilename, $storedPath, $uploadedBy, $comment, $contentHash);
    }

    /**
     * @return array{import: BankStatementImport, imported: int, skipped: int, total: int}
     */
    public function importExcel(
        string $absolutePath,
        string $originalFilename,
        ?string $storedPath = null,
        ?int $uploadedBy = null,
        ?string $comment = null,
        ?string $contentHash = null,
    ): array {
        $contentHash ??= is_file($absolutePath) ? hash_file('sha256', $absolutePath) : null;
        $this->guardAgainstDuplicateFile($contentHash);

        $parsed = $this->excelParser->parse($absolutePath);

        return $this->storeParsed($parsed, $originalFilename, $storedPath, $uploadedBy, $comment, $contentHash);
    }

    /**
     * @return array{import: BankStatementImport, imported: int, skipped: int, total: int}
     */
    public function importXml(
        string $xml,
        string $originalFilename,
        ?string $storedPath = null,
        ?int $uploadedBy = null,
        ?string $comment = null,
        ?string $contentHash = null,
    ): array {
        $contentHash ??= $xml === '' ? null : hash('sha256', $xml);
        $this->guardAgainstDuplicateFile($contentHash);

        $parsed = $this->xmlParser->parse($xml);

        return $this->storeParsed($parsed, $originalFilename, $storedPath, $uploadedBy, $comment, $contentHash);
    }

    /**
     * @param  array{
     *     message_id: ?string,
     *     statement_id: ?string,
     *     account_iban: ?string,
     *     bank_name: ?string,
     *     currency: ?string,
     *     period_from: ?string,
     *     period_to: ?string,
     *     transactions: list<array<string, mixed>>
     * }  $parsed
     * @return array{import: BankStatementImport, imported: int, skipped: int, total: int}
     */
    private function storeParsed(
        array $parsed,
        string $originalFilename,
        ?string $storedPath,
        ?int $uploadedBy,
        ?string $comment,
        ?string $contentHash = null,
    ): array {
        $comment = filled($comment) ? trim($comment) : null;
        $accountIban = CompanyBankAccount::canonicalizeStatementAccount($parsed['account_iban'] ?? null);
        $prepared = $this->prepareTransactions($parsed, $accountIban, $comment);

        $newRows = [];
        $skipped = 0;
        $seenInFile = [];

        foreach ($prepared as $row) {
            $fingerprint = $row['fingerprint'];

            if (isset($seenInFile[$fingerprint])) {
                $skipped++;

                continue;
            }

            $seenInFile[$fingerprint] = true;
            $newRows[$fingerprint] = $row;
        }

        if ($newRows !== []) {
            $existing = BankStatementTransaction::query()
                ->whereIn('fingerprint', array_keys($newRows))
                ->pluck('fingerprint')
                ->all();

            foreach ($existing as $fingerprint) {
                unset($newRows[$fingerprint]);
                $skipped++;
            }
        }

        if ($newRows === []) {
            if ($skipped > 0) {
                // Full overlap with existing lines — reject instead of inserting a duplicate shell.
                throw BankStatementDuplicateException::allLinesAlreadyImported();
            }

            throw new BankStatementXmlException('No income or outcome lines could be stored from this file.');
        }

        return DB::transaction(function () use ($parsed, $originalFilename, $storedPath, $uploadedBy, $comment, $contentHash, $accountIban, $newRows, $skipped): array {
            $import = $this->resolveImportRecord([
                'original_filename' => $originalFilename,
                'stored_path' => $storedPath,
                'content_hash' => $contentHash,
                'bank_name' => $parsed['bank_name'],
                'account_iban' => $accountIban,
                'account_currency' => $parsed['currency'],
                'statement_id' => $parsed['statement_id'],
                'message_id' => $parsed['message_id'],
                'comment' => $comment,
                'period_from' => $parsed['period_from'],
                'period_to' => $parsed['period_to'],
                'uploaded_by' => $uploadedBy,
                'transaction_count' => count($parsed['transactions']),
                'imported_count' => 0,
                'skipped_count' => 0,
            ]);

            $imported = 0;

            foreach ($newRows as $row) {
                $transaction = $row['transaction'];

                BankStatementTransaction::query()->create([
                    'bank_statement_import_id' => $import->getKey(),
                    'fingerprint' => $row['fingerprint'],
                    'month_key' => substr((string) $transaction['booking_date'], 0, 7),
                    'booking_date' => $transaction['booking_date'],
                    'value_date' => $transaction['value_date'],
                    'direction' => $transaction['direction'],
                    'amount' => $transaction['amount'],
                    'currency' => $transaction['currency'],
                    'account_iban' => $transaction['account_iban'],
                    'bank_name' => $transaction['bank_name'],
                    'counterpart_name' => $transaction['counterpart_name'],
                    'counterpart_iban' => $transaction['counterpart_iban'],
                    'counterpart_bic' => $this->limit($transaction['counterpart_bic'] ?? null, 11),
                    'description' => $transaction['description'],
                    'comment' => $comment,
                    'end_to_end_id' => $this->limit($transaction['end_to_end_id'] ?? null, 255),
                    'account_servicer_ref' => $this->limit($transaction['account_servicer_ref'] ?? null, 255),
                    'transaction_id' => $this->limit($transaction['transaction_id'] ?? null, 255),
                    'no_document_needed' => $row['no_document_needed'],
                ]);

                $imported++;
            }

            $import->update([
                'imported_count' => $imported,
                'skipped_count' => $skipped,
            ]);

            return [
                'import' => $import->refresh(),
                'imported' => $imported,
                'skipped' => $skipped,
                'total' => count($parsed['transactions']),
            ];
        });
    }

    /**
     * @param  array{
     *     account_iban: ?string,
     *     transactions: list<array<string, mixed>>
     * }  $parsed
     * @return list<array{transaction: array<string, mixed>, fingerprint: string, no_document_needed: bool}>
     */
    private function prepareTransactions(array $parsed, ?string $accountIban, ?string $comment): array
    {
        $rows = [];

        foreach ($parsed['transactions'] as $transaction) {
            $transaction['account_iban'] = CompanyBankAccount::canonicalizeStatementAccount(
                $transaction['account_iban'] ?? $accountIban,
            ) ?? $accountIban;

            $cleanedCounterpart = $this->limit(
                BankStatementCounterpartResolver::resolve(
                    $transaction['counterpart_name'] ?? null,
                    $transaction['description'] ?? null,
                    $transaction['end_to_end_id'] ?? null,
                ) ?? ($transaction['counterpart_name'] ?? null),
                255,
            );

            $transaction['counterpart_name'] = $cleanedCounterpart;
            $transaction['description'] = BankStatementDescriptionCleaner::clean(
                $transaction['description'] ?? null,
                $cleanedCounterpart,
            );

            $fromFile = $transaction['direction'] instanceof BankStatementDirection
                ? $transaction['direction']
                : BankStatementDirection::tryFrom((string) $transaction['direction']);

            if ($fromFile === null) {
                continue;
            }

            $transaction['direction'] = BankStatementDirectionResolver::resolve(
                $fromFile,
                $transaction['account_iban'] ?? null,
                $transaction['counterpart_iban'] ?? null,
                $cleanedCounterpart,
                $transaction['description'] ?? null,
                $transaction['end_to_end_id'] ?? null,
                $comment,
            );

            $rows[] = [
                'transaction' => $transaction,
                'fingerprint' => $this->fingerprint($transaction),
                'no_document_needed' => BankStatementDirectionResolver::isOwnTransfer(
                    $transaction['account_iban'] ?? null,
                    $transaction['counterpart_iban'] ?? null,
                    $cleanedCounterpart,
                    $transaction['description'] ?? null,
                    $transaction['end_to_end_id'] ?? null,
                    $comment,
                ) || BankStatementDirectionResolver::isBankServiceFee(
                    $cleanedCounterpart,
                    $transaction['description'] ?? null,
                    $transaction['end_to_end_id'] ?? null,
                    $comment,
                ),
            ];
        }

        return $rows;
    }

    /**
     * Parse a statement file and insert only missing fingerprints.
     * Used for recovery after partial data loss — does not create a new import
     * when $attachToImport is provided.
     *
     * @return array{import: BankStatementImport, imported: int, skipped: int, total: int}
     */
    public function importMissingFromFile(
        string $absolutePath,
        string $originalFilename,
        ?BankStatementImport $attachToImport = null,
        ?int $uploadedBy = null,
        ?string $comment = null,
    ): array {
        $extension = strtolower((string) pathinfo($originalFilename, PATHINFO_EXTENSION));
        $binary = is_file($absolutePath) ? (string) file_get_contents($absolutePath) : '';
        if ($binary === '') {
            throw new BankStatementXmlException('Could not read bank statement file.');
        }

        $parsed = match (true) {
            in_array($extension, ['xls', 'xlsx', 'xlsm'], true) => $this->excelParser->parse($absolutePath),
            $extension === 'pdf' => $this->pdfParser->parse($absolutePath),
            default => $this->xmlParser->parse($binary),
        };

        $accountIban = CompanyBankAccount::canonicalizeStatementAccount($parsed['account_iban'] ?? null);
        $prepared = $this->prepareTransactions($parsed, $accountIban, $comment);
        $contentHash = hash('sha256', $binary);

        $seenInFile = [];
        $newRows = [];
        $skipped = 0;

        foreach ($prepared as $row) {
            $fingerprint = $row['fingerprint'];
            if (isset($seenInFile[$fingerprint])) {
                $skipped++;

                continue;
            }
            $seenInFile[$fingerprint] = true;
            $newRows[$fingerprint] = $row;
        }

        $existing = BankStatementTransaction::query()
            ->whereIn('fingerprint', array_keys($newRows))
            ->pluck('fingerprint')
            ->all();

        foreach ($existing as $fingerprint) {
            unset($newRows[$fingerprint]);
            $skipped++;
        }

        if ($newRows === []) {
            $import = $attachToImport;
            if ($import === null) {
                $import = BankStatementImport::query()->where('content_hash', $contentHash)->first()
                    ?? BankStatementImport::query()->create([
                        'original_filename' => $originalFilename,
                        'content_hash' => $contentHash,
                        'bank_name' => $parsed['bank_name'] ?? null,
                        'account_iban' => $accountIban,
                        'account_currency' => $parsed['currency'] ?? null,
                        'statement_id' => $parsed['statement_id'] ?? null,
                        'message_id' => $parsed['message_id'] ?? null,
                        'comment' => $comment ?? 'restore: no new rows',
                        'period_from' => $parsed['period_from'] ?? null,
                        'period_to' => $parsed['period_to'] ?? null,
                        'uploaded_by' => $uploadedBy,
                        'transaction_count' => count($parsed['transactions']),
                        'imported_count' => 0,
                        'skipped_count' => $skipped,
                    ]);
            }

            return [
                'import' => $import->refresh(),
                'imported' => 0,
                'skipped' => $skipped,
                'total' => count($parsed['transactions']),
            ];
        }

        return DB::transaction(function () use ($parsed, $originalFilename, $uploadedBy, $comment, $contentHash, $accountIban, $newRows, $skipped, $attachToImport): array {
            $import = $attachToImport;
            if ($import === null) {
                $import = BankStatementImport::query()->where('content_hash', $contentHash)->first();
            }

            if ($import === null) {
                $import = BankStatementImport::query()->create([
                    'original_filename' => $originalFilename,
                    'content_hash' => $contentHash,
                    'bank_name' => $parsed['bank_name'] ?? null,
                    'account_iban' => $accountIban,
                    'account_currency' => $parsed['currency'] ?? null,
                    'statement_id' => $parsed['statement_id'] ?? null,
                    'message_id' => $parsed['message_id'] ?? null,
                    'comment' => $comment,
                    'period_from' => $parsed['period_from'] ?? null,
                    'period_to' => $parsed['period_to'] ?? null,
                    'uploaded_by' => $uploadedBy,
                    'transaction_count' => count($parsed['transactions']),
                    'imported_count' => 0,
                    'skipped_count' => 0,
                ]);
            }

            $imported = 0;
            foreach ($newRows as $row) {
                $transaction = $row['transaction'];

                BankStatementTransaction::query()->create([
                    'bank_statement_import_id' => $import->getKey(),
                    'fingerprint' => $row['fingerprint'],
                    'month_key' => substr((string) $transaction['booking_date'], 0, 7),
                    'booking_date' => $transaction['booking_date'],
                    'value_date' => $transaction['value_date'],
                    'direction' => $transaction['direction'],
                    'amount' => $transaction['amount'],
                    'currency' => $transaction['currency'],
                    'account_iban' => $transaction['account_iban'],
                    'bank_name' => $transaction['bank_name'],
                    'counterpart_name' => $transaction['counterpart_name'],
                    'counterpart_iban' => $transaction['counterpart_iban'],
                    'counterpart_bic' => $this->limit($transaction['counterpart_bic'] ?? null, 11),
                    'description' => $transaction['description'],
                    'comment' => $comment,
                    'end_to_end_id' => $this->limit($transaction['end_to_end_id'] ?? null, 255),
                    'account_servicer_ref' => $this->limit($transaction['account_servicer_ref'] ?? null, 255),
                    'transaction_id' => $this->limit($transaction['transaction_id'] ?? null, 255),
                    'no_document_needed' => $row['no_document_needed'],
                ]);

                $imported++;
            }

            $import->update([
                'imported_count' => (int) $import->imported_count + $imported,
                'skipped_count' => $skipped,
                'transaction_count' => max((int) $import->transaction_count, count($parsed['transactions'])),
            ]);

            return [
                'import' => $import->refresh(),
                'imported' => $imported,
                'skipped' => $skipped,
                'total' => count($parsed['transactions']),
            ];
        });
    }

    private function guardAgainstDuplicateFile(?string $contentHash): void
    {
        if (! filled($contentHash)) {
            return;
        }

        $existing = BankStatementImport::query()
            ->where('content_hash', $contentHash)
            ->first();

        if ($existing === null) {
            return;
        }

        // Allow re-upload when the prior import never made it to SharePoint / Documents
        // (resolveImportRecord will reuse that row instead of inserting a duplicate hash).
        if (! $existing->hasSharepointLink()) {
            return;
        }

        throw BankStatementDuplicateException::alreadyImportedFile($existing->displayName());
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function resolveImportRecord(array $attributes): BankStatementImport
    {
        $contentHash = $attributes['content_hash'] ?? null;

        if (filled($contentHash)) {
            $existing = BankStatementImport::query()
                ->where('content_hash', $contentHash)
                ->first();

            if ($existing !== null) {
                $existing->fill(collect($attributes)->except(['content_hash'])->all());
                $existing->save();

                return $existing->refresh();
            }
        }

        return BankStatementImport::query()->create($attributes);
    }

    /**
     * @param  array<string, mixed>  $transaction
     */
    public function fingerprint(array $transaction): string
    {
        $direction = $transaction['direction'] instanceof BankStatementDirection
            ? $transaction['direction']->value
            : (string) $transaction['direction'];

        return hash('sha256', implode('|', [
            strtoupper((string) ($transaction['account_iban'] ?? '')),
            (string) ($transaction['account_servicer_ref'] ?? ''),
            (string) ($transaction['end_to_end_id'] ?? ''),
            (string) ($transaction['transaction_id'] ?? ''),
            (string) ($transaction['booking_date'] ?? ''),
            $direction,
            number_format((float) ($transaction['amount'] ?? 0), 2, '.', ''),
            strtoupper((string) ($transaction['currency'] ?? 'EUR')),
            strtoupper((string) ($transaction['counterpart_iban'] ?? '')),
            mb_strtoupper((string) ($transaction['counterpart_name'] ?? '')),
        ]));
    }

    private function limit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        return Str::limit($value, $length, '');
    }
}
