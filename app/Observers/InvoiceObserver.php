<?php

namespace App\Observers;

use App\Enums\ActivityLogEvent;
use App\Models\Invoice;
use App\Services\ActivityLogger;

class InvoiceObserver
{
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
}
