<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Services\InvoiceCustomerVatSynchronizer;
use App\Services\VatRateResolver;
use Illuminate\Console\Command;
use Throwable;

class RegenerateInvoicesFromCustomerVat extends Command
{
    protected $signature = 'app:regenerate-invoices-from-customer-vat
                            {--dry-run : Show changes without saving or uploading PDFs}
                            {--limit= : Process at most N invoices}
                            {--id= : Process a single invoice id}';

    protected $description = 'Recalculate sales invoice VAT/amounts from customer VAT settings and regenerate SharePoint PDFs';

    public function handle(InvoiceCustomerVatSynchronizer $synchronizer): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $onlyId = filled($this->option('id')) ? (int) $this->option('id') : null;

        if (! $dryRun && ! SharepointSetting::instance()->isReady()) {
            $this->error('SharePoint is not ready. Enable and configure System → SharePoint first.');

            return self::FAILURE;
        }

        $query = Invoice::query()
            ->with(['order.user.vatRate', 'order.orderItems.product', 'contact', 'vatRate'])
            ->where('is_own_account', false)
            ->where(function ($q): void {
                $q->whereNotNull('order_id')
                    ->orWhere('invoice_number', 'like', 'FTE%')
                    ->orWhereNotNull('income_type_id');
            })
            ->orderBy('id');

        if ($onlyId !== null) {
            $query->whereKey($onlyId);
        }

        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $processed = 0;

        $query->chunkById(20, function ($chunk) use (
            $synchronizer,
            $dryRun,
            $limit,
            &$updated,
            &$skipped,
            &$failed,
            &$processed,
        ): bool {
            foreach ($chunk as $invoice) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }
                $processed++;

                $label = '#'.$invoice->getKey().' '.($invoice->invoice_number ?: '(no number)');

                try {
                    $customer = $synchronizer->resolveCustomer($invoice);
                    $vatRate = $customer !== null
                        ? VatRateResolver::forUser($customer)
                        : null;

                    $before = sprintf(
                        'net=%s vat=%s gross=%s rate=%s',
                        $invoice->sum_without_vat,
                        $invoice->vat,
                        $invoice->sum_inc_vat,
                        $invoice->vatRate?->classificator ?? '-',
                    );

                    if ($dryRun) {
                        if ($vatRate === null && $customer === null) {
                            // Preview will use contact fallback inside sync; show PVM4 default for unknown export contacts
                            $vatRate = VatRateResolver::default();
                        }
                        $net = round((float) $invoice->sum_without_vat, 2);
                        [$n, $v, $g] = VatRateResolver::calculateFromNet($net, $vatRate);
                        $this->line(sprintf(
                            '[dry-run] %s %s → %s | %s → net=%s vat=%s gross=%s',
                            $label,
                            $customer?->company_name ?? ($invoice->contact?->company_name ?? '—'),
                            $vatRate?->classificator ?? '-',
                            $before,
                            $n,
                            $v,
                            $g,
                        ));
                        $updated++;

                        continue;
                    }

                    $result = $synchronizer->sync($invoice);
                    $invoice->refresh();
                    $this->info(sprintf(
                        '%s %s → %s | %s → net=%s vat=%s gross=%s + PDF',
                        $label,
                        $result['customer'],
                        $result['vat_classificator'],
                        $before,
                        $invoice->sum_without_vat,
                        $invoice->vat,
                        $invoice->sum_inc_vat,
                    ));
                    $updated++;
                } catch (Throwable $exception) {
                    $failed++;
                    $this->error($label.' failed: '.$exception->getMessage());
                }
            }

            return $limit === null || $processed < $limit;
        });

        $this->newLine();
        $this->info(sprintf('Done. updated=%d, skipped=%d, failed=%d', $updated, $skipped, $failed));

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
