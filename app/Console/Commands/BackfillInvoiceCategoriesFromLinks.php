<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillInvoiceCategoriesFromLinks extends Command
{
    protected $signature = 'invoices:backfill-categories-from-links
                            {--dry-run : Show changes without writing}';

    protected $description = 'Apply missing invoice categories (provider default / heuristics) for bank-linked invoices';

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
            $this->info('No bank-linked invoices found.');

            return self::SUCCESS;
        }

        $updated = 0;
        $unchanged = 0;

        Invoice::query()
            ->whereKey($invoiceIds)
            ->whereNull('income_type_id')
            ->whereNull('expense_type_id')
            ->where(function ($query): void {
                $query->whereNull('is_own_account')->orWhere('is_own_account', false);
            })
            ->with('contact')
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use ($dryRun, &$updated, &$unchanged): void {
                foreach ($invoices as $invoice) {
                    /** @var Invoice $invoice */
                    if ($dryRun) {
                        $canSuggest = $invoice->looksLikeOwnAccountTransfer()
                            || $invoice->contact?->defaultFinanceTypeValue() !== null
                            || ($invoice->looksLikeSalesInvoice() && Invoice::goodsIncomeTypeId() !== null);

                        if ($canSuggest) {
                            $source = $invoice->contact?->defaultFinanceTypeValue()
                                ?? ($invoice->looksLikeOwnAccountTransfer() ? 'own-account' : 'sales/Prekės');
                            $this->line(sprintf(
                                '#%d %s — would set from %s',
                                $invoice->getKey(),
                                $invoice->invoice_number ?? '—',
                                $source,
                            ));
                            $updated++;
                        } else {
                            $unchanged++;
                        }

                        continue;
                    }

                    if ($invoice->applySuggestedCategoryIfMissing()) {
                        $fresh = $invoice->fresh(['incomeType', 'expenseType']);
                        $this->line(sprintf(
                            '#%d %s → %s',
                            $invoice->getKey(),
                            $invoice->invoice_number ?? '—',
                            $fresh?->financeCategoryName() ?? '—',
                        ));
                        $updated++;
                    } else {
                        $unchanged++;
                    }
                }
            });

        $this->info(($dryRun ? 'Would update' : 'Updated').": {$updated}; no suggestion: {$unchanged}");

        return self::SUCCESS;
    }
}
