<?php

namespace App\Services;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\InvoiceSeries;
use App\Models\Order;

class OrderInvoiceCreator
{
    public static function createFromShippedOrder(Order $order): Invoice
    {
        $existing = Invoice::query()->where('order_id', $order->id)->first();

        if ($existing !== null) {
            return self::syncAmountsAndPdf($existing, $order);
        }

        $order->loadMissing('user');
        OrderResource::recalculateOrderAmount($order->fresh());
        $order = $order->fresh(['user']);

        $company = CompanySetting::instance();
        $contact = $company->syncContact();
        $series = InvoiceSeries::default();

        if ($series === null) {
            throw new \RuntimeException('No default invoice series configured. Add one under Financial options → Invoices series and mark it as default.');
        }

        $seriesNumber = $series->nextSeriesNumber();
        $invoiceNumber = $series->formatNumber($seriesNumber);
        $vatRate = VatRateResolver::forUser($order->user);
        [$sumWithoutVat, $vat, $sumIncVat] = VatRateResolver::calculateAmounts((float) $order->amount, $vatRate);

        $invoice = Invoice::query()->create([
            'order_id' => $order->id,
            'invoice_series_id' => $series->id,
            'series_number' => $seriesNumber,
            'invoice_number' => $invoiceNumber,
            'contact_id' => $contact->id,
            'invoice_date' => now()->toDateString(),
            'sum_without_vat' => $sumWithoutVat,
            'vat' => $vat,
            'sum_inc_vat' => $sumIncVat,
            'vat_rate_id' => $vatRate?->id,
            'upload_date' => now()->toDateString(),
            'uploaded_by' => auth()->id(),
            'pdf_path' => null,
            'file_name' => $invoiceNumber.'.pdf',
            'file_mime' => 'application/pdf',
            'file_content' => null,
        ]);

        return InvoicePdfGenerator::storeForInvoice($invoice, $order, $company);
    }

    public static function syncAmountsAndPdf(Invoice $invoice, ?Order $order = null): Invoice
    {
        $invoice->loadMissing(['order.user', 'vatRate']);
        $order ??= $invoice->order;

        if ($order === null) {
            throw new \RuntimeException('Only order sales invoices can be synchronized.');
        }

        OrderResource::recalculateOrderAmount($order->fresh());
        $order = $order->fresh(['user']);

        $vatRate = VatRateResolver::forInvoice($invoice, $order->user);
        [$sumWithoutVat, $vat, $sumIncVat] = VatRateResolver::calculateAmounts((float) $order->amount, $vatRate);

        $invoice->update([
            'sum_without_vat' => $sumWithoutVat,
            'vat' => $vat,
            'sum_inc_vat' => $sumIncVat,
            'vat_rate_id' => $vatRate?->id ?? $invoice->vat_rate_id,
        ]);

        return InvoicePdfGenerator::storeForInvoice($invoice->fresh(), $order);
    }
}
