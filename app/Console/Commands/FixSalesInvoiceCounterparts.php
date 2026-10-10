<?php

namespace App\Console\Commands;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Invoice;
use Illuminate\Console\Command;

class FixSalesInvoiceCounterparts extends Command
{
    protected $signature = 'app:fix-sales-invoice-counterparts
                            {--dry-run : Show changes without saving}';

    protected $description = 'Point income/sales invoices at the customer counterpart instead of our own company';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        try {
            $issuerId = (int) CompanySetting::instance()->syncContact()->getKey();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $futuraIds = Contact::query()
            ->where('company_name', 'like', '%Futura Textiles%')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $ownIds = array_values(array_unique(array_filter([$issuerId, ...$futuraIds])));

        $query = Invoice::query()
            ->with(['order.user', 'contact'])
            ->whereIn('contact_id', $ownIds)
            ->where(function ($builder): void {
                $builder
                    ->whereNotNull('order_id')
                    ->orWhere('invoice_number', 'like', 'FTE%');
            });

        $fixed = 0;
        $skipped = 0;

        $query->orderBy('id')->each(function (Invoice $invoice) use ($dryRun, &$fixed, &$skipped): void {
            if (! $invoice->looksLikeSalesInvoice() && $invoice->financeCategoryKind() !== 'income') {
                $skipped++;

                return;
            }

            $customer = $invoice->order?->user;
            if ($customer === null) {
                $this->line('skip #'.$invoice->getKey().' '.$invoice->invoice_number.' (no order customer)');
                $skipped++;

                return;
            }

            $buyer = Contact::syncFromCustomer($customer);
            $from = $invoice->contact?->company_name ?? '#'.$invoice->contact_id;
            $to = $buyer->company_name;

            $this->line(($dryRun ? '[dry-run] ' : '').$invoice->invoice_number.': '.$from.' → '.$to);

            if (! $dryRun) {
                $invoice->forceFill(['contact_id' => $buyer->getKey()])->save();
            }
            $fixed++;
        });

        $this->newLine();
        $this->info(($dryRun ? 'Would fix ' : 'Fixed ').$fixed.' · skipped '.$skipped);

        return self::SUCCESS;
    }
}
