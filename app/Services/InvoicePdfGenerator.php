<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\CompanySetting;
use App\Models\Invoice;
use App\Models\Order;
use App\Services\OrderPackageCalculator;
use App\Services\VatRateResolver;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

class InvoicePdfGenerator
{
    public static function generateSalesInvoice(array $data): string
    {
        return Pdf::loadView('pdf.sales-invoice', $data)
            ->setPaper('a4', 'portrait')
            ->output();
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildOrderInvoiceData(
        Order $order,
        Invoice $invoice,
        ?CompanySetting $company = null,
        ?InvoiceLanguage $language = null,
    ): array {
        $order->loadMissing(['user', 'orderItems', 'package']);
        $company ??= CompanySetting::instance();
        $customer = $order->user;
        $language ??= InvoiceLanguage::normalize($customer?->invoice_language);
        $t = InvoicePdfTranslator::strings($language);
        $lines = OrderResource::orderLineItemsSummary($order);
        $subtotal = round(collect($lines)->sum('line_total'), 2);
        $shipping = round((float) ($order->shipping_cost ?? 0), 2);
        $vatRateModel = VatRateResolver::forInvoice($invoice, $customer);
        $vatRate = VatRateResolver::numericRate($vatRateModel);
        $packageStats = OrderPackageCalculator::calculate($order);
        $packageTrackingLine = OrderPackageCalculator::formatTrackingLine($order, $packageStats, $language);
        $packageWeightsLine = OrderPackageCalculator::formatWeightsLine($packageStats, $language);

        return [
            'locale' => $language->value,
            't' => $t,
            'invoiceNumber' => $invoice->invoice_number ?? ('INV-'.$invoice->id),
            'invoiceDate' => optional($invoice->invoice_date)->format('Y-m-d') ?? now()->format('Y-m-d'),
            'orderNumber' => $order->id,
            'trackingNumber' => $order->tracking_number,
            'issuer' => [
                'name' => $company->company_name,
                'companyId' => $company->company_id,
                'vat' => $company->company_vat,
                'country' => $company->company_country,
                'address' => $company->company_address,
                'email' => $company->company_email,
                'phone' => $company->company_phone,
                'contactName' => $company->contact_name,
                'contactEmail' => $company->contact_email,
                'contactPhone' => $company->contact_phone,
            ],
            'customer' => [
                'name' => self::customerDisplayName($customer),
                'companyName' => $customer?->company_name,
                'companyId' => $customer?->company_code,
                'vat' => $customer?->company_vat,
                'country' => $customer?->company_country,
                'address' => $customer?->company_address ?: $customer?->company_shipping_address,
                'email' => $customer?->email,
                'phone' => $customer?->phone,
            ],
            'lines' => $lines,
            'subtotal' => $subtotal,
            'shipping' => $shipping,
            'sumWithoutVat' => (float) $invoice->sum_without_vat,
            'vat' => (float) $invoice->vat,
            'sumIncVat' => (float) $invoice->sum_inc_vat,
            'vatRate' => $vatRate,
            'vatClassificator' => VatRateResolver::displayLabel($vatRateModel),
            'vatLegalLines' => InvoicePdfTranslator::legalLines($vatRateModel, $language),
            'packageTrackingLine' => $packageTrackingLine,
            'packageWeightsLine' => $packageWeightsLine,
            'logoDataUri' => self::logoDataUri(),
            'brandColor' => '#2b3a67',
        ];
    }

    public static function storeForInvoice(Invoice $invoice, Order $order, ?CompanySetting $company = null): Invoice
    {
        $order->loadMissing('user');
        $language = InvoiceLanguage::normalize($order->user?->invoice_language);
        $data = self::buildOrderInvoiceData($order, $invoice, $company, $language);
        $pdfBinary = self::generateSalesInvoice($data);
        $fileName = ($invoice->invoice_number ?? 'invoice-'.$invoice->id).'.pdf';
        $storedPath = 'invoices/generated/'.$fileName;

        if (filled($invoice->pdf_path) && str_starts_with((string) $invoice->pdf_path, 'invoices/generated/')) {
            Storage::disk('public')->delete($invoice->pdf_path);
        }

        Storage::disk('public')->put($storedPath, $pdfBinary);

        $invoice->update([
            'pdf_path' => $storedPath,
            'file_name' => $fileName,
            'file_mime' => 'application/pdf',
            'file_content' => base64_encode($pdfBinary),
        ]);

        return $invoice->fresh();
    }

    public static function generateForInvoice(Invoice $invoice, InvoiceLanguage $language): string
    {
        $invoice->loadMissing(['order.user', 'vatRate']);

        if ($invoice->order === null) {
            throw new \RuntimeException('Only order sales invoices can be generated as PDF.');
        }

        $data = self::buildOrderInvoiceData($invoice->order, $invoice, null, $language);

        return self::generateSalesInvoice($data);
    }

    public static function refreshSalesInvoicePdf(Invoice $invoice): Invoice
    {
        return OrderInvoiceCreator::syncAmountsAndPdf($invoice);
    }

    private static function customerDisplayName(?object $customer): string
    {
        if ($customer === null) {
            return '—';
        }

        $name = trim(implode(' ', array_filter([
            $customer->company_name,
            $customer->name,
            $customer->surname,
        ])));

        return $name !== '' ? $name : '—';
    }

    private static function logoDataUri(): ?string
    {
        $pngPath = public_path('images/logo-invoice.png');

        if (is_readable($pngPath)) {
            $contents = file_get_contents($pngPath);

            if ($contents !== false) {
                return 'data:image/png;base64,'.base64_encode($contents);
            }
        }

        $svgPath = public_path('images/logo.svg');

        if (! is_readable($svgPath)) {
            return null;
        }

        $contents = file_get_contents($svgPath);

        if ($contents === false) {
            return null;
        }

        return 'data:image/svg+xml;base64,'.base64_encode($contents);
    }
}
