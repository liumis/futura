<?php

namespace App\Services;

use App\Enums\OrderStatus;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Smalot\PdfParser\Parser as PdfParser;
use Spatie\Permission\Models\Role;
use Throwable;

class FuturaSalesInvoicePdfImporter
{
    public function __construct(
        private readonly InvoiceOcrService $ocr,
        private readonly HistoricalSalesOrderImporter $orders,
    ) {}

    /**
     * @param  list<string>  $paths
     * @return array{
     *     files: int,
     *     created: int,
     *     skipped_existing: int,
     *     orders_created: int,
     *     orders_reused: int,
     *     items_created: int,
     *     bank_linked: int,
     *     sharepoint: int,
     *     errors: list<string>
     * }
     */
    public function importPaths(array $paths, bool $linkBank = true, bool $uploadSharepoint = true): array
    {
        $stats = [
            'files' => 0,
            'created' => 0,
            'skipped_existing' => 0,
            'orders_created' => 0,
            'orders_reused' => 0,
            'items_created' => 0,
            'bank_linked' => 0,
            'sharepoint' => 0,
            'errors' => [],
        ];

        $uploadedBy = User::query()->orderBy('id')->value('id');
        if ($uploadedBy === null) {
            throw new InvalidArgumentException('Cannot import invoices: no users exist to set as uploaded_by.');
        }

        $role = Role::findOrCreate(User::ROLE_CUSTOMER, 'web');
        $productsByCode = $this->productLookup();

        foreach ($paths as $path) {
            if (! is_string($path) || $path === '' || ! is_file($path)) {
                $stats['errors'][] = (string) $path.': file not found';

                continue;
            }

            $stats['files']++;
            $basename = basename($path);

            try {
                $parsed = $this->parsePdf($path, $productsByCode);
            } catch (Throwable $exception) {
                $stats['errors'][] = $basename.': '.$exception->getMessage();

                continue;
            }

            $invoiceNumber = $parsed['invoice_number'];
            if (Invoice::query()->where('invoice_number', $invoiceNumber)->exists()) {
                $stats['skipped_existing']++;

                continue;
            }

            try {
                $result = DB::transaction(function () use ($parsed, $uploadedBy, $role, $basename, &$stats): array {
                    [$customer, $customerCreated] = $this->resolveCustomer($parsed, $role);
                    unset($customerCreated);

                    $historyName = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX
                        .$parsed['customer_code'].'|'
                        .$parsed['invoice_date'].'|'
                        .$parsed['invoice_number'];

                    $order = Order::query()->where('name', $historyName)->first();
                    if ($order === null) {
                        // Legacy same-day key without invoice number (only reuse if still free).
                        $legacyName = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX
                            .$parsed['customer_code'].'|'
                            .$parsed['invoice_date'];
                        $legacy = Order::query()->where('name', $legacyName)->first();
                        if ($legacy !== null && ! Invoice::query()->where('order_id', $legacy->id)->exists()) {
                            $order = $legacy;
                            $stats['orders_reused']++;
                        }
                    } else {
                        $stats['orders_reused']++;
                    }

                    if ($order === null) {
                        $order = Order::query()->create([
                            'name' => $historyName,
                            'status' => OrderStatus::Completed,
                            'stock_reserved' => false,
                            'amount' => $parsed['sum_without_vat'] > 0
                                ? $parsed['sum_without_vat']
                                : $parsed['sum_inc_vat'],
                            'shipping_cost' => 0,
                            'order_date' => Carbon::parse($parsed['invoice_date'])->setTime(12, 0),
                            'user_id' => $customer->id,
                        ]);

                        foreach ($parsed['lines'] as $line) {
                            /** @var Product $product */
                            $product = $line['product'];
                            $meters = (int) $line['meters'];
                            $amount = $product->rollAmountFromMeters($meters);

                            OrderItem::query()->create([
                                'order_id' => $order->id,
                                'product_id' => $product->id,
                                'amount' => max(1, $amount),
                            ]);
                            $stats['items_created']++;
                        }

                        $stats['orders_created']++;
                    }

                    $buyerContactId = Contact::syncFromCustomer($customer)->id;

                    $invoice = Invoice::query()->create([
                        'order_id' => $order->id,
                        'contact_id' => $buyerContactId,
                        'invoice_series_id' => null,
                        'series_number' => null,
                        'invoice_number' => $parsed['invoice_number'],
                        'invoice_date' => $parsed['invoice_date'],
                        'sum_without_vat' => $parsed['sum_without_vat'],
                        'vat' => $parsed['vat'],
                        'sum_inc_vat' => $parsed['sum_inc_vat'],
                        'vat_rate_id' => VatRateResolver::forUser($customer)?->id,
                        'payment_term' => Invoice::paymentTermFromCustomer($customer),
                        'paid_amount' => 0,
                        'paid' => false,
                        'income_type_id' => Invoice::goodsIncomeTypeId(),
                        'expense_type_id' => null,
                        'upload_date' => $parsed['invoice_date'],
                        'uploaded_by' => $uploadedBy,
                        'pdf_path' => '',
                        'file_name' => $basename,
                        'file_mime' => 'application/pdf',
                        'file_content' => null,
                    ]);

                    InvoiceDefaultFinanceLines::apply($invoice);
                    $stats['created']++;

                    return ['invoice' => $invoice, 'path' => $parsed['path'], 'basename' => $basename];
                });
            } catch (Throwable $exception) {
                $stats['errors'][] = $basename.': '.$exception->getMessage();

                continue;
            }

            if ($uploadSharepoint && SharepointSetting::instance()->isReady()) {
                try {
                    SharepointInvoiceUploader::ingestBinary(
                        $result['invoice'],
                        (string) file_get_contents($result['path']),
                        $result['basename'],
                    );
                    $stats['sharepoint']++;
                } catch (Throwable $exception) {
                    $stats['errors'][] = $result['basename'].': SharePoint '.$exception->getMessage();
                }
            }
        }

        if ($linkBank) {
            $stats['bank_linked'] = BankStatementInvoiceMatcher::autoLinkUnlinked();
        }

        return $stats;
    }

    /**
     * @param  array<string, Product>  $productsByCode
     * @return array{
     *     path: string,
     *     invoice_number: string,
     *     invoice_date: string,
     *     customer_code: string,
     *     customer_name: string,
     *     provider_company_vat: ?string,
     *     provider_company_id: ?string,
     *     sum_without_vat: float,
     *     vat: float,
     *     sum_inc_vat: float,
     *     lines: list<array{product: Product, meters: int, code: string}>
     * }
     */
    public function parsePdf(string $path, ?array $productsByCode = null): array
    {
        $productsByCode ??= $this->productLookup();
        $ocr = $this->ocr->extract($path);

        if (($ocr['is_own_sales_invoice'] ?? false) !== true) {
            throw new InvalidArgumentException('Not recognized as a Futura FTE sales invoice.');
        }

        $invoiceNumber = $this->normalizeFteNumber((string) ($ocr['invoice_number'] ?? ''));
        if ($invoiceNumber === null) {
            throw new InvalidArgumentException('Could not read invoice number.');
        }

        $invoiceDate = (string) ($ocr['invoice_date'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $invoiceDate)) {
            // Fall back to filename FTE0148.2026.09.02.pdf
            if (preg_match('/(\d{4})[.\-](\d{2})[.\-](\d{2})/', basename($path), $m) === 1) {
                $invoiceDate = $m[1].'-'.$m[2].'-'.$m[3];
            } else {
                throw new InvalidArgumentException('Could not read invoice date.');
            }
        }

        $customerName = trim((string) ($ocr['provider_company_name'] ?? ''));
        if ($customerName === '') {
            throw new InvalidArgumentException('Could not read buyer / customer name.');
        }

        $customerCode = $this->inferCustomerCode($customerName);
        $text = $this->pdfText($path);
        $lines = $this->extractProductLines($text, $productsByCode);

        $net = round((float) ($ocr['sum_without_vat'] ?? 0), 2);
        $vat = round((float) ($ocr['vat'] ?? 0), 2);
        $gross = round((float) ($ocr['sum_inc_vat'] ?? ($net + $vat)), 2);

        // OCR sometimes swaps net/VAT on 21% LT invoices (vat ends up larger than net).
        if ($vat > $net && $net > 0 && abs(round($vat * 0.21, 2) - $net) <= 0.05) {
            [$net, $vat] = [$vat, $net];
        }
        if ($gross <= 0 && ($net + $vat) > 0) {
            $gross = round($net + $vat, 2);
        } elseif (abs(($net + $vat) - $gross) > 0.05 && $net > 0 && $vat >= 0) {
            $gross = round($net + $vat, 2);
        }

        return [
            'path' => $path,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => $invoiceDate,
            'customer_code' => $customerCode,
            'customer_name' => $customerName,
            'provider_company_vat' => filled($ocr['provider_company_vat'] ?? null) ? (string) $ocr['provider_company_vat'] : null,
            'provider_company_id' => filled($ocr['provider_company_id'] ?? null) ? (string) $ocr['provider_company_id'] : null,
            'sum_without_vat' => $net,
            'vat' => $vat,
            'sum_inc_vat' => $gross,
            'lines' => $lines,
        ];
    }

    /**
     * @return list<string>
     */
    public static function discoverPdfPaths(string $directory): array
    {
        $directory = rtrim($directory, '\\/');
        $paths = glob($directory.DIRECTORY_SEPARATOR.'FTE*.pdf') ?: [];
        $paths = array_values(array_filter($paths, 'is_file'));
        usort($paths, static function (string $a, string $b): int {
            return strnatcasecmp(basename($a), basename($b));
        });

        return $paths;
    }

    /**
     * Prefer September 2026 FTE sales PDFs that are not yet imported.
     *
     * @return list<string>
     */
    public static function discoverSeptember2026Paths(string $directory): array
    {
        return array_values(array_filter(
            self::discoverPdfPaths($directory),
            static function (string $path): bool {
                $name = basename($path);

                return preg_match('/^FTE0(148|149|15\d|16[0-3])\b/i', $name) === 1
                    || preg_match('/FTE0(148|149|15\d|16[0-3]).*2026\.09/i', $name) === 1;
            },
        ));
    }

    /**
     * @param  array{
     *     customer_code: string,
     *     customer_name: string,
     *     provider_company_vat: ?string,
     *     provider_company_id: ?string
     * }  $parsed
     * @return array{0: User, 1: bool}
     */
    private function resolveCustomer(array $parsed, Role $role): array
    {
        $result = $this->orders->resolveCustomer($parsed['customer_code'], $parsed['customer_name'], $role);
        $user = $result[0];

        if (filled($parsed['provider_company_vat']) && blank($user->company_vat)) {
            $user->forceFill(['company_vat' => $parsed['provider_company_vat']])->save();
        }

        return $result;
    }

    private function inferCustomerCode(string $customerName): string
    {
        $code = $this->orders->inferCustomerCode($customerName);
        if ($code !== '') {
            return strtoupper($code);
        }

        $norm = preg_replace('/[^A-Z0-9]/', '', Str::upper(Str::ascii($customerName))) ?? '';

        return $norm !== '' ? Str::limit($norm, 40, '') : 'CUSTOMER';
    }

    private function normalizeFteNumber(string $value): ?string
    {
        $value = strtoupper(preg_replace('/\s+/', '', $value) ?? '');
        if (preg_match('/^FTE-?(\d+)$/', $value, $m) !== 1) {
            return null;
        }

        return 'FTE'.str_pad($m[1], 4, '0', STR_PAD_LEFT);
    }

    private function pdfText(string $path): string
    {
        $parser = new PdfParser;
        $text = $parser->parseFile($path)->getText();

        return preg_replace('/\s+/u', '', $text) ?? '';
    }

    /**
     * @param  array<string, Product>  $productsByCode
     * @return list<array{product: Product, meters: int, code: string}>
     */
    private function extractProductLines(string $compactText, array $productsByCode): array
    {
        $upper = Str::upper($compactText);
        $lines = [];

        // Longest codes first so AGNONA102ARCTIC30 wins over AGNONA102.
        $codes = array_keys($productsByCode);
        usort($codes, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        $consumed = [];

        foreach ($codes as $code) {
            if (strlen($code) < 8) {
                continue;
            }

            $offset = 0;
            while (($pos = strpos($upper, $code, $offset)) !== false) {
                $spanKey = $pos.':'.($pos + strlen($code));
                if (isset($consumed[$spanKey])) {
                    $offset = $pos + 1;

                    continue;
                }

                // Skip overlaps with already matched longer codes.
                $overlaps = false;
                foreach ($consumed as $span => $_) {
                    [$s, $e] = array_map('intval', explode(':', $span));
                    if ($pos < $e && ($pos + strlen($code)) > $s) {
                        $overlaps = true;
                        break;
                    }
                }
                if ($overlaps) {
                    $offset = $pos + 1;

                    continue;
                }

                $product = $productsByCode[$code];
                $meters = 30;
                if (preg_match('/(\d{2})$/', $code, $m) === 1) {
                    $meters = max(1, (int) $m[1]);
                }

                $lines[] = [
                    'product' => $product,
                    'meters' => $meters,
                    'code' => $code,
                ];
                $consumed[$spanKey] = true;
                $offset = $pos + strlen($code);
            }
        }

        return $lines;
    }

    /**
     * @return array<string, Product>
     */
    private function productLookup(): array
    {
        return $this->orders->productLookup();
    }
}
