<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Services\InvoicePdfGenerator;
use App\Services\SharepointInvoiceUploader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

class GenerateInvoicePdfs extends Command
{
    protected $signature = 'app:generate-invoice-pdfs
                            {--dry-run : Report what would be done without uploading}
                            {--force : Regenerate / re-upload even if SharePoint link already exists}
                            {--limit= : Process at most N invoices}
                            {--id= : Process a single invoice id}';

    protected $description = 'Generate LT+EN sales invoice PDFs and store them on SharePoint only';

    public function handle(): int
    {
        $settings = SharepointSetting::instance();

        if (! $settings->isReady() && ! $this->option('dry-run')) {
            $this->error('SharePoint is not ready. Enable and configure System → SharePoint first.');

            return self::FAILURE;
        }

        $force = (bool) $this->option('force');
        $dryRun = (bool) $this->option('dry-run');
        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $onlyId = filled($this->option('id')) ? (int) $this->option('id') : null;

        $query = Invoice::query()
            ->with(['order.user', 'order.orderItems.product.color.collection', 'order.orderItems.product.productType', 'order.package', 'contact', 'vatRate'])
            ->where('is_own_account', false)
            ->orderBy('id');

        if ($onlyId !== null) {
            $query->whereKey($onlyId);
        }

        $generated = 0;
        $migrated = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;
        $stop = false;

        $query->chunkById(25, function ($chunk) use (
            $force,
            $dryRun,
            $limit,
            &$generated,
            &$migrated,
            &$skipped,
            &$failed,
            &$processed,
            &$stop,
        ): bool {
            foreach ($chunk as $invoice) {
                if ($limit !== null && $processed >= $limit) {
                    $stop = true;

                    return false;
                }

                $processed++;
                $label = '#'.$invoice->getKey().' '.($invoice->invoice_number ?: '(no number)');

                try {
                    $outcome = $this->processInvoice($invoice, $force, $dryRun);
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error($label.' failed: '.$exception->getMessage());

                    continue;
                }

                if ($outcome === 'generated') {
                    $generated++;
                    $this->info(($dryRun ? '[dry-run] ' : '').$label.' → LT+EN PDF → SharePoint');
                } elseif ($outcome === 'migrated') {
                    $migrated++;
                    $this->info(($dryRun ? '[dry-run] ' : '').$label.' → migrate local → SharePoint');
                } else {
                    $skipped++;
                }
            }

            return ! $stop;
        });

        $this->newLine();
        $this->info(sprintf(
            'Done. generated=%d, migrated=%d, skipped=%d, failed=%d',
            $generated,
            $migrated,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return 'generated'|'migrated'|'skipped'
     */
    private function processInvoice(Invoice $invoice, bool $force, bool $dryRun): string
    {
        $hasLocal = $this->hasLocalFile($invoice);
        $canGenerate = $this->canGenerateSalesPdf($invoice);
        $hasBoth = $invoice->hasBilingualSalesPdfs();

        if ($hasBoth && ! $force) {
            if ($hasLocal && ! $dryRun) {
                $this->clearLocalResidue($invoice);
            }

            return 'skipped';
        }

        // Prefer migrating an existing uploaded non-generated file when we cannot generate sales PDFs.
        if ($hasLocal && ! $canGenerate) {
            if ($dryRun) {
                return 'migrated';
            }

            InvoicePdfGenerator::migrateLocalFileToSharepoint($invoice);

            return 'migrated';
        }

        if ($canGenerate) {
            if ($dryRun) {
                return 'generated';
            }

            InvoicePdfGenerator::generateBothLanguagesOnSharepoint($invoice);

            return 'generated';
        }

        if ($hasLocal) {
            if ($dryRun) {
                return 'migrated';
            }

            InvoicePdfGenerator::migrateLocalFileToSharepoint($invoice);

            return 'migrated';
        }

        return 'skipped';
    }

    private function canGenerateSalesPdf(Invoice $invoice): bool
    {
        if ($invoice->is_own_account) {
            return false;
        }

        if ($invoice->order_id) {
            return true;
        }

        $number = strtoupper(trim((string) ($invoice->invoice_number ?? '')));

        return str_starts_with($number, 'FTE')
            || $invoice->income_type_id !== null;
    }

    private function hasLocalFile(Invoice $invoice): bool
    {
        if (filled($invoice->file_content)) {
            return true;
        }

        $path = trim((string) ($invoice->pdf_path ?? ''));
        if ($path === '') {
            return false;
        }

        return Storage::disk('public')->exists($path) || Storage::disk('local')->exists($path);
    }

    private function clearLocalResidue(Invoice $invoice): void
    {
        $path = trim((string) ($invoice->pdf_path ?? ''));
        if ($path !== '') {
            SharepointInvoiceUploader::deleteLocalPath($path);
        }

        if (filled($invoice->pdf_path) || filled($invoice->file_content)) {
            $invoice->forceFill([
                'pdf_path' => '',
                'file_content' => null,
            ])->save();
        }
    }
}
