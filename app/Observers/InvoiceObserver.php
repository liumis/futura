<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\Currency;
use App\Models\Invoice;
use App\Services\ActivityLogger;

class InvoiceObserver
{
    public function saving(Invoice $invoice): void
    {
        $invoice->currency = Currency::normalize($invoice->currency);

        $hasExplicitFx = $invoice->isDirty('currency_rate')
            || $invoice->isDirty('sum_inc_vat_eur');

        if ($hasExplicitFx) {
            // Keep card/manual FX snapshots; only recompute EUR when rate changed without a EUR amount.
            if ($invoice->isDirty('currency_rate') && ! $invoice->isDirty('sum_inc_vat_eur')) {
                $invoice->syncEuroConversion(refreshRate: false);
            }
        } else {
            $shouldRefreshRate = ! $invoice->exists
                || $invoice->isDirty('currency')
                || $invoice->isDirty('sum_inc_vat')
                || $invoice->currency_rate === null
                || $invoice->sum_inc_vat_eur === null;

            $invoice->syncEuroConversion(refreshRate: $shouldRefreshRate);
        }

        // When the invoice total changes, rebuild paid / partly paid from linked bank payments.
        if ($invoice->exists && $invoice->isDirty('sum_inc_vat')) {
            $invoice->applyBankLinkPaymentState();
        }

        $invoice->normalizePaymentState();
        $this->syncPrivateProviderFromContact($invoice);
    }

    public function created(Invoice $invoice): void
    {
        ActivityLogger::log(
            ActivityLogEvent::InvoiceCreated,
            'Invoice #'.$invoice->id.' created',
            $invoice,
        );
    }

    public function updated(Invoice $invoice): void
    {
        ActivityLogger::log(
            ActivityLogEvent::InvoiceUpdated,
            'Invoice #'.$invoice->id.' updated',
            $invoice,
            ['changes' => $invoice->getChanges()],
        );
    }

    public function deleted(Invoice $invoice): void
    {
        ActivityLogger::log(
            ActivityLogEvent::InvoiceDeleted,
            'Invoice #'.$invoice->id.' deleted',
            null,
            ['deleted_invoice_id' => $invoice->getKey()],
        );
    }

    private function syncPrivateProviderFromContact(Invoice $invoice): void
    {
        if (! $invoice->contact_id) {
            return;
        }

        if ($invoice->exists && ! $invoice->isDirty('contact_id')) {
            return;
        }

        $invoice->unsetRelation('contact');
        $invoice->load('contact');

        if ($invoice->contact?->private_provider) {
            $invoice->private_provider = true;
        } elseif ($invoice->exists && $invoice->isDirty('contact_id')) {
            $invoice->private_provider = false;
        }
    }
}
