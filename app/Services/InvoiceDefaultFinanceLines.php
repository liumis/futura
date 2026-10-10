<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\InvoiceCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoiceDefaultFinanceLines
{
    public const VAT_OUTPUT = '4492';

    public const VAT_INPUT = '2441';

    public const CUSTOMERS = '2410';

    public const SUPPLIERS = '4430';

    public const GOODS_REVENUE = '5000';

    public const SERVICE_REVENUE = '5001';

    public const INVENTORY = '2040';

    public const COGS = '6000';

    /**
     * @return array<string, InvoiceCode>
     */
    public static function codes(array $extra = []): array
    {
        $defaults = [
            self::CUSTOMERS => 'Pirkėjų skolų vertė',
            self::SERVICE_REVENUE => 'Suteiktų paslaugų pajamos',
            self::VAT_OUTPUT => 'Mokėtinas pridėtinės vertės mokestis',
            self::VAT_INPUT => 'Gautinas pridėtinės vertės mokestis',
            self::INVENTORY => 'Pirktų prekių, skirtų perparduoti, įsigijimo',
            self::COGS => 'Parduotų prekių savikaina',
            self::GOODS_REVENUE => 'Parduotų prekių pajamos',
            self::SUPPLIERS => 'Skolos tiekėjams už prekes ir paslaugas',
        ];

        foreach ($extra as $code => $name) {
            $defaults[$code] = $name;
        }

        $existing = InvoiceCode::query()
            ->whereIn('code', array_keys($defaults))
            ->get()
            ->keyBy('code');

        $codes = [];

        foreach ($defaults as $code => $name) {
            $record = $existing->get($code);

            if ($record === null) {
                $record = InvoiceCode::query()->create([
                    'code' => $code,
                    'name' => $name,
                ]);
            }

            $codes[$code] = $record;
        }

        return $codes;
    }

    /**
     * @return array<string, array{name: string, invoice_code_id: int, name_select: int, debit: float, credit: float}>
     */
    public static function buildForForm(Invoice $invoice): array
    {
        $lines = [];

        foreach (self::buildRows($invoice) as $row) {
            $lines[(string) Str::uuid()] = $row;
        }

        return $lines;
    }

    /**
     * Persist default finance lines. Skips when lines already exist unless $force is true.
     * Skips GL-generated payroll carriers and own-account transfers.
     */
    public static function apply(Invoice $invoice, bool $force = false): int
    {
        if ($invoice->gl_generated_type) {
            return 0;
        }

        if ($invoice->is_own_account) {
            if ($force) {
                $invoice->financeLines()->delete();
            }

            return 0;
        }

        $invoice->loadMissing('financeLines');

        if (! $force && $invoice->financeLines->isNotEmpty()) {
            return 0;
        }

        $rows = array_values(self::buildRows($invoice));

        if ($rows === []) {
            if ($force) {
                $invoice->financeLines()->delete();
            }

            return 0;
        }

        DB::transaction(function () use ($invoice, $rows): void {
            $invoice->financeLines()->delete();

            foreach ($rows as $index => $row) {
                $invoice->financeLines()->create([
                    'name' => $row['name'],
                    'invoice_code_id' => $row['invoice_code_id'],
                    'credit' => $row['credit'],
                    'debit' => $row['debit'],
                    'sort_order' => $index,
                ]);
            }
        });

        $invoice->unsetRelation('financeLines');

        return count($rows);
    }

    /**
     * @return array{invoices: int, lines: int}
     */
    public static function applyMissing(?string $fromDate = null): array
    {
        $invoices = 0;
        $lines = 0;

        Invoice::query()
            ->whereNull('gl_generated_type')
            ->where(fn ($q) => $q->where('is_own_account', false)->orWhereNull('is_own_account'))
            ->whereDoesntHave('financeLines')
            ->when($fromDate, fn ($q) => $q->whereDate('invoice_date', '>=', $fromDate))
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use (&$invoices, &$lines): void {
                foreach ($chunk as $invoice) {
                    $written = self::apply($invoice);
                    if ($written > 0) {
                        $invoices++;
                        $lines += $written;
                    }
                }
            });

        return [
            'invoices' => $invoices,
            'lines' => $lines,
        ];
    }

    /**
     * Force-regenerate lines for non-GL invoices in range.
     *
     * @return array{invoices: int, lines: int}
     */
    public static function recalculateFrom(string $fromDate): array
    {
        $invoices = 0;
        $lines = 0;

        Invoice::query()
            ->whereNull('gl_generated_type')
            ->where(fn ($q) => $q->where('is_own_account', false)->orWhereNull('is_own_account'))
            ->whereDate('invoice_date', '>=', $fromDate)
            ->orderBy('id')
            ->chunkById(50, function ($chunk) use (&$invoices, &$lines): void {
                foreach ($chunk as $invoice) {
                    $written = self::apply($invoice, force: true);
                    $invoices++;
                    $lines += $written;
                }
            });

        return [
            'invoices' => $invoices,
            'lines' => $lines,
        ];
    }

    /**
     * @return list<array{name: string, invoice_code_id: int, name_select: int, debit: float, credit: float}>
     */
    public static function buildRows(Invoice $invoice): array
    {
        if ($invoice->is_own_account || $invoice->gl_generated_type) {
            return [];
        }

        $invoice->loadMissing([
            'expenseType.invoiceCode',
            'order.orderItems.product.productType',
            'order.orderItems.product.color.collection',
        ]);

        if ($invoice->expense_type_id && ! $invoice->income_type_id) {
            return self::buildPurchaseRows($invoice);
        }

        return self::buildSalesRows($invoice);
    }

    /**
     * @return list<array{name: string, invoice_code_id: int, name_select: int, debit: float, credit: float}>
     */
    private static function buildSalesRows(Invoice $invoice): array
    {
        $codes = self::codes();

        $sumIncVat = round((float) $invoice->sum_inc_vat, 2);
        $vat = round((float) $invoice->vat, 2);
        $sumWithoutVat = round((float) $invoice->sum_without_vat, 2);
        $shipping = round((float) ($invoice->order?->shipping_cost ?? 0), 2);
        $cogs = self::estimateCogs($invoice);

        // Credit notes / negative sales: flip signs so lines stay meaningful.
        $sign = $sumIncVat < 0 || ($sumIncVat == 0.0 && $sumWithoutVat < 0) ? -1.0 : 1.0;
        $sumIncVat = abs($sumIncVat);
        $vat = abs($vat);
        $sumWithoutVat = abs($sumWithoutVat);
        $shipping = abs($shipping);
        $cogs = abs($cogs);

        if ($vat > 100000 || ($sumIncVat > 0.005 && $vat > $sumIncVat + 0.05)) {
            $vat = 0.0;
        }

        if ($sumIncVat < 0.005 && $sumWithoutVat >= 0.005) {
            $sumIncVat = round($sumWithoutVat + $vat, 2);
        }

        $goodsRevenue = round(max(0, $sumWithoutVat - $shipping), 2);
        if ($sumIncVat >= 0.005) {
            // Force revenue so customers = shipping + vat + goods.
            $goodsRevenue = round(max(0, $sumIncVat - $shipping - $vat), 2);
        } elseif ($goodsRevenue < 0.005 && $sumWithoutVat >= 0.005) {
            $goodsRevenue = $sumWithoutVat;
            $sumIncVat = round($goodsRevenue + $shipping + $vat, 2);
        }

        $template = [
            ['code' => self::CUSTOMERS, 'debit' => $sumIncVat, 'credit' => 0.0],
            ['code' => self::SERVICE_REVENUE, 'debit' => 0.0, 'credit' => $shipping],
            ['code' => self::VAT_OUTPUT, 'debit' => 0.0, 'credit' => $vat],
            ['code' => self::INVENTORY, 'debit' => 0.0, 'credit' => $cogs],
            ['code' => self::COGS, 'debit' => $cogs, 'credit' => 0.0],
            ['code' => self::GOODS_REVENUE, 'debit' => 0.0, 'credit' => $goodsRevenue],
        ];

        if ($sign < 0) {
            $template = array_map(static function (array $row): array {
                return [
                    'code' => $row['code'],
                    'debit' => $row['credit'],
                    'credit' => $row['debit'],
                ];
            }, $template);
        }

        return self::rowsFromTemplate($template, $codes);
    }

    /**
     * @return list<array{name: string, invoice_code_id: int, name_select: int, debit: float, credit: float}>
     */
    private static function buildPurchaseRows(Invoice $invoice): array
    {
        $expenseCode = ExpenseTypeAccountMapper::codeFor($invoice->expenseType);
        $codes = self::codes([
            $expenseCode->code => $expenseCode->name,
        ]);
        $codes[$expenseCode->code] = $expenseCode;

        $sumIncVat = round((float) $invoice->sum_inc_vat, 2);
        $vat = round((float) $invoice->vat, 2);
        $net = round((float) $invoice->sum_without_vat, 2);

        $isCreditNote = $invoice->isSupplierCreditNote() || $sumIncVat < 0 || $net < 0;
        $sumIncVat = abs($sumIncVat);
        $vat = abs($vat);
        $net = abs($net);

        // OCR garbage: huge VAT or VAT larger than gross.
        if ($vat > 100000 || ($sumIncVat > 0.005 && $vat > $sumIncVat + 0.05)) {
            $vat = 0.0;
        }

        if ($sumIncVat < 0.005 && $net >= 0.005) {
            $sumIncVat = round($net + $vat, 2);
        }

        if ($net < 0.005 && $sumIncVat >= 0.005) {
            $net = round(max(0, $sumIncVat - $vat), 2);
        }

        // Keep purchase entry balanced: expense + VAT = suppliers.
        $net = round(max(0, $sumIncVat - $vat), 2);

        $template = [
            ['code' => $expenseCode->code, 'debit' => $net, 'credit' => 0.0],
            ['code' => self::VAT_INPUT, 'debit' => $vat, 'credit' => 0.0],
            ['code' => self::SUPPLIERS, 'debit' => 0.0, 'credit' => $sumIncVat],
        ];

        if ($isCreditNote) {
            $template = array_map(static function (array $row): array {
                return [
                    'code' => $row['code'],
                    'debit' => $row['credit'],
                    'credit' => $row['debit'],
                ];
            }, $template);
        }

        return self::rowsFromTemplate($template, $codes);
    }

    /**
     * @param  list<array{code: string, debit: float, credit: float}>  $template
     * @param  array<string, InvoiceCode>  $codes
     * @return list<array{name: string, invoice_code_id: int, name_select: int, debit: float, credit: float}>
     */
    private static function rowsFromTemplate(array $template, array $codes): array
    {
        $rows = [];

        foreach ($template as $row) {
            $debit = round((float) $row['debit'], 2);
            $credit = round((float) $row['credit'], 2);

            if (abs($debit) < 0.005 && abs($credit) < 0.005) {
                continue;
            }

            $code = $codes[$row['code']] ?? ExpenseTypeAccountMapper::ensureCode($row['code']);

            $rows[] = [
                'name' => $code->name,
                'invoice_code_id' => (int) $code->id,
                'name_select' => (int) $code->id,
                'debit' => $debit,
                'credit' => $credit,
            ];
        }

        return $rows;
    }

    public static function estimateCogs(Invoice $invoice): float
    {
        $order = $invoice->order;

        if ($order === null) {
            return 0.0;
        }

        $order->loadMissing('orderItems.product.productType', 'orderItems.product.color.collection');
        $total = 0.0;

        foreach ($order->orderItems as $item) {
            $product = $item->product;

            if ($product === null) {
                continue;
            }

            $quantity = max(0, (int) $item->amount);
            $unitCost = $product->collectionUnitCost();
            $total += $product->lineAmount($unitCost, $quantity);
        }

        return round($total, 2);
    }
}
