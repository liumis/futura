<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResyncInvoicePaymentsFromBankLinks extends Command
{
    protected $signature = 'invoices:resync-payments-from-bank-links
                            {--dry-run : Show changes without writing}';

    protected $description = 'Recalculate invoice paid / partly paid status from linked bank statement payments';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $invoiceIds = DB::table('bank_statement_transaction_invoice')
            ->distinct()
            ->pluck('invoice_id')
            ->merge(
                DB::table('bank_statement_transactions')
                    ->whereNotNull('invoice_id')
                    ->distinct()
                    ->pluck('invoice_id'),
            )
            ->unique()
            ->filter()
            ->values()
            ->all();

        if ($invoiceIds === []) {
            $this->info('No invoices with bank payment links found.');

            return self::SUCCESS;
        }

        $updated = 0;
        $unchanged = 0;

        Invoice::query()
            ->whereKey($invoiceIds)
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use ($dryRun, &$updated, &$unchanged): void {
                foreach ($invoices as $invoice) {
                    /** @var Invoice $invoice */
                    $beforePaid = round((float) ($invoice->paid_amount ?? 0), 2);
                    $beforeFlag = (bool) $invoice->paid;

                    if (! $invoice->applyBankLinkPaymentState()) {
                        $unchanged++;

                        continue;
                    }

                    $invoice->normalizePaymentState();

                    $afterPaid = round((float) ($invoice->paid_amount ?? 0), 2);
                    $afterFlag = (bool) $invoice->paid;

                    if (abs($beforePaid - $afterPaid) < 0.005 && $beforeFlag === $afterFlag) {
                        $unchanged++;

                        continue;
                    }

                    $this->line(sprintf(
                        '#%d %s: paid_amount %s → %s, paid %s → %s [%s]',
                        $invoice->getKey(),
                        $invoice->invoice_number ?? '—',
                        number_format($beforePaid, 2, '.', ''),
                        number_format($afterPaid, 2, '.', ''),
                        $beforeFlag ? 'yes' : 'no',
                        $afterFlag ? 'yes' : 'no',
                        $invoice->paymentSettlementLabel(),
                    ));

                    if (! $dryRun) {
                        $invoice->save();
                    }

                    $updated++;
                }
            });

        $this->info(($dryRun ? 'Would update' : 'Updated').": {$updated}; unchanged: {$unchanged}");

        return self::SUCCESS;
    }
}
