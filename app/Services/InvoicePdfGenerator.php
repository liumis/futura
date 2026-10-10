<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

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
            'issuer' => self::issuerBlock($company),
            'customer' => self::customerBlockFromUser($customer),
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
            'brandColor' => '#111111',
        ];
    }

    /**
     * PDF data for sales invoices without an order (e.g. Futura FTE imports).
     *
     * @return array<string, mixed>
     */
    public static function buildStandaloneInvoiceData(
        Invoice $invoice,
        ?CompanySetting $company = null,
        ?InvoiceLanguage $language = null,
    ): array {
        $invoice->loadMissing(['contact', 'vatRate']);
        $company ??= CompanySetting::instance();
        $language ??= InvoiceLanguage::English;
        $t = InvoicePdfTranslator::strings($language);
        $vatRateModel = VatRateResolver::forInvoice($invoice, null);
        $vatRate = VatRateResolver::numericRate($vatRateModel);
        $net = round((float) $invoice->sum_without_vat, 2);
        $goodsLabel = $language === InvoiceLanguage::Lithuanian ? 'Prekės' : 'Goods';

        $lines = [[
            'collection' => $goodsLabel,
            'color' => '—',
            'product_code' => '—',
            'size' => '—',
            'amount' => 1,
            'unit_price' => $net,
            'line_total' => $net,
        ]];

        return [
            'locale' => $language->value,
            't' => $t,
            'invoiceNumber' => $invoice->invoice_number ?? ('INV-'.$invoice->id),
            'invoiceDate' => optional($invoice->invoice_date)->format('Y-m-d') ?? now()->format('Y-m-d'),
            'orderNumber' => null,
            'trackingNumber' => null,
            'issuer' => self::issuerBlock($company),
            'customer' => self::customerBlockFromContact($invoice->contact),
            'lines' => $lines,
            'subtotal' => $net,
            'shipping' => 0.0,
            'sumWithoutVat' => $net,
            'vat' => (float) $invoice->vat,
            'sumIncVat' => (float) $invoice->sum_inc_vat,
            'vatRate' => $vatRate,
            'vatClassificator' => VatRateResolver::displayLabel($vatRateModel),
            'vatLegalLines' => InvoicePdfTranslator::legalLines($vatRateModel, $language),
            'packageTrackingLine' => null,
            'packageWeightsLine' => null,
            'logoDataUri' => self::logoDataUri(),
            'brandColor' => '#111111',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildInvoiceData(
        Invoice $invoice,
        ?CompanySetting $company = null,
        ?InvoiceLanguage $language = null,
    ): array {
        $invoice->loadMissing(['order.user', 'order.orderItems', 'order.package', 'contact', 'vatRate']);

        if ($invoice->order !== null) {
            return self::buildOrderInvoiceData($invoice->order, $invoice, $company, $language);
        }

        return self::buildStandaloneInvoiceData($invoice, $company, $language);
    }

    public static function storeForInvoice(Invoice $invoice, Order $order, ?CompanySetting $company = null): Invoice
    {
        return self::generateBothLanguagesOnSharepoint($invoice, $company);
    }

    /**
     * Generate sales PDF from invoice (+ order lines when available) and store on SharePoint only.
     * Does not recalculate invoice amounts from the order.
     */
    public static function generateAndStoreOnSharepoint(Invoice $invoice, ?CompanySetting $company = null): Invoice
    {
        return self::generateBothLanguagesOnSharepoint($invoice, $company);
    }

    /**
     * Generate LT + EN sales PDFs and store both on SharePoint (no local copies).
     */
    public static function generateBothLanguagesOnSharepoint(Invoice $invoice, ?CompanySetting $company = null): Invoice
    {
        if ($invoice->is_own_account) {
            throw new RuntimeException('Own-account transfers do not get a sales invoice PDF.');
        }

        $invoice->loadMissing(['order.user', 'contact', 'vatRate']);
        $preferred = InvoiceLanguage::normalize(
            $invoice->order?->user?->invoice_language
        );

        foreach ([InvoiceLanguage::Lithuanian, InvoiceLanguage::English] as $language) {
            $data = self::buildInvoiceData($invoice, $company, $language);
            $binary = self::generateSalesInvoice($data);
            SharepointInvoiceUploader::ingestLanguageBinary(
                $invoice->fresh() ?? $invoice,
                $binary,
                $language->value,
                setAsPrimary: $language === $preferred,
            );
        }

        $invoice = $invoice->fresh() ?? $invoice;
        $invoice->forceFill([
            'file_mime' => 'application/pdf',
            'pdf_path' => '',
            'file_content' => null,
        ])->save();

        return $invoice->fresh() ?? $invoice;
    }

    /**
     * Move an existing local / DB-stored invoice file to SharePoint and clear local storage.
     */
    public static function migrateLocalFileToSharepoint(Invoice $invoice): Invoice
    {
        $binary = null;
        $localPath = null;
        $fileName = filled($invoice->file_name) ? (string) $invoice->file_name : ($invoice->displayNumber().'.pdf');

        if (filled($invoice->pdf_path) && $invoice->pdf_path !== '') {
            $localPath = (string) $invoice->pdf_path;
            foreach (['public', 'local'] as $disk) {
                if (Storage::disk($disk)->exists($localPath)) {
                    $contents = Storage::disk($disk)->get($localPath);
                    if (is_string($contents) && $contents !== '') {
                        $binary = $contents;
                        break;
                    }
                }
            }
        }

        if ($binary === null && filled($invoice->file_content)) {
            $decoded = base64_decode((string) $invoice->file_content, true);
            if (is_string($decoded) && $decoded !== '') {
                $binary = $decoded;
            }
        }

        if ($binary === null || $binary === '') {
            throw new RuntimeException('Invoice #'.$invoice->getKey().' has no local file to migrate.');
        }

        $invoice->forceFill([
            'file_mime' => $invoice->file_mime ?: 'application/pdf',
        ])->save();

        SharepointInvoiceUploader::ingestBinary($invoice, $binary, $fileName, $localPath);

        return $invoice->fresh() ?? $invoice;
    }

    public static function generateForInvoice(Invoice $invoice, InvoiceLanguage $language): string
    {
        $invoice->loadMissing(['order.user', 'vatRate', 'contact']);

        ActivityLogger::logReportDownloaded(
            'Sales invoice PDF '.$invoice->displayNumber(),
            'pdf',
            $invoice,
            [
                'invoice_id' => $invoice->getKey(),
                'invoice_number' => $invoice->invoice_number,
                'order_id' => $invoice->order_id,
                'language' => $language->value,
            ],
        );

        return self::generateSalesInvoice(self::buildInvoiceData($invoice, null, $language));
    }

    public static function refreshSalesInvoicePdf(Invoice $invoice): Invoice
    {
        if ($invoice->order_id) {
            return OrderInvoiceCreator::syncAmountsAndPdf($invoice);
        }

        return self::generateBothLanguagesOnSharepoint($invoice);
    }

    /**
     * @return array<string, mixed>
     */
    private static function issuerBlock(CompanySetting $company): array
    {
        return [
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
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerBlockFromUser(?User $customer): array
    {
        return [
            'name' => self::customerDisplayName($customer),
            'companyName' => $customer?->company_name,
            'companyId' => $customer?->company_code,
            'vat' => $customer?->company_vat,
            'country' => $customer?->company_country,
            'address' => $customer?->company_address ?: $customer?->company_shipping_address,
            'email' => $customer?->email,
            'phone' => $customer?->phone,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function customerBlockFromContact(?Contact $contact): array
    {
        $name = trim((string) ($contact?->company_name ?: $contact?->contact_name ?: ''));

        return [
            'name' => $name !== '' ? $name : '—',
            'companyName' => $contact?->company_name,
            'companyId' => $contact?->company_id,
            'vat' => $contact?->company_vat,
            'country' => $contact?->company_country,
            'address' => $contact?->company_address,
            'email' => $contact?->company_email ?: $contact?->contact_email,
            'phone' => $contact?->company_phone ?: $contact?->contact_phone,
        ];
    }

    private static function customerDisplayName(?object $customer): string
    {
        if ($customer === null) {
            return '—';
        }

        $name = trim(implode(' ', array_filter([
            $customer->company_name ?? null,
            $customer->name ?? null,
            $customer->surname ?? null,
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
