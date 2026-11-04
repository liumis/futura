<?php

namespace App\Jobs;

use App\Exceptions\BankStatementDuplicateException;
use App\Exceptions\BankStatementXmlException;
use App\Models\BankStatementTransaction;
use App\Models\User;
use App\Notifications\BankStatementImportFinishedNotification;
use App\Services\BankStatementDocumentGenerator;
use App\Services\BankStatements\BankStatementImporter;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Services\BankStatements\BankStatementPaymentMatcher;
use App\Services\BankStatements\BankStatementSalaryMatcher;
use App\Services\SharepointBankStatementUploader;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Runs after the HTTP response so Cloudflare/proxy does not 504 during PDF OCR.
 */
class ImportBankStatementJob
{
    use Dispatchable;

    public function __construct(
        public string $relativePath,
        public string $originalFilename,
        public ?int $userId,
        public ?string $comment = null,
    ) {}

    public function handle(BankStatementImporter $importer): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(300);
        }

        if (! Storage::disk('local')->exists($this->relativePath)) {
            $this->notifyUser(
                success: false,
                body: $this->originalFilename.': uploaded file not found on the server (try uploading again).',
            );

            return;
        }

        $absolutePath = Storage::disk('local')->path($this->relativePath);
        $binary = (string) Storage::disk('local')->get($this->relativePath);

        try {
            $result = $importer->importFile(
                $absolutePath,
                $this->originalFilename,
                $this->relativePath,
                $this->userId,
                $this->comment,
            );
        } catch (BankStatementDuplicateException $exception) {
            $this->notifyUser(success: false, body: $this->originalFilename.': '.$exception->getMessage(), duplicate: true);

            return;
        } catch (BankStatementXmlException $exception) {
            $this->notifyUser(success: false, body: $this->originalFilename.': '.$exception->getMessage());

            return;
        } catch (Throwable $exception) {
            Log::error('ImportBankStatementJob failed', [
                'file' => $this->originalFilename,
                'message' => $exception->getMessage(),
            ]);
            $this->notifyUser(success: false, body: $this->originalFilename.': '.$exception->getMessage());

            return;
        }

        $warnings = [];

        try {
            SharepointBankStatementUploader::ingestLocalFile($result['import'], $binary, $this->relativePath);
        } catch (Throwable $exception) {
            $warnings[] = 'SharePoint upload failed: '.$exception->getMessage();
        }

        try {
            $user = $this->userId !== null ? User::query()->find($this->userId) : null;
            BankStatementDocumentGenerator::syncForImport(
                $result['import']->fresh() ?? $result['import'],
                $user,
            );
        } catch (Throwable $exception) {
            $warnings[] = 'Documents entry failed: '.$exception->getMessage();
        }

        $body = $result['imported'].' new line(s) stored';
        if ($result['skipped'] > 0) {
            $body .= ', '.$result['skipped'].' already in the system';
        }
        $body .= ' from '.$this->originalFilename.'.';

        $unlinked = BankStatementTransaction::query()
            ->withoutLinkedDocument()
            ->get();
        $suggestedInvoices = BankStatementInvoiceMatcher::suggestFor($unlinked)->count();
        $suggestedPayments = BankStatementPaymentMatcher::suggestFor($unlinked)->count();
        $suggestedSalaries = BankStatementSalaryMatcher::suggestFor($unlinked)->count();
        if ($suggestedInvoices > 0) {
            $body .= ' '.$suggestedInvoices.' existing invoice(s) can be confirmed.';
        }
        if ($suggestedPayments > 0) {
            $body .= ' '.$suggestedPayments.' existing contract(s) can be confirmed.';
        }
        if ($suggestedSalaries > 0) {
            $body .= ' '.$suggestedSalaries.' salary payment(s) can be confirmed.';
        }
        if ($warnings !== []) {
            $body .= ' '.implode(' ', $warnings);
        }

        $this->notifyUser(success: true, body: $body);
    }

    private function notifyUser(bool $success, string $body, bool $duplicate = false): void
    {
        if ($this->userId === null) {
            return;
        }

        $user = User::query()->find($this->userId);
        if ($user === null) {
            return;
        }

        $user->notify(new BankStatementImportFinishedNotification(
            success: $success,
            duplicate: $duplicate,
            body: $body,
            filename: $this->originalFilename,
        ));
    }
}
