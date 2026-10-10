<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\User;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use Throwable;

class FuturaSalesInvoiceImporter
{
    public const HISTORY_NAME_PREFIX = HistoricalSalesOrderImporter::HISTORY_NAME_PREFIX;

    /**
     * @return array{
     *     rows: int,
     *     created: int,
     *     skipped_existing: int,
     *     linked_orders: int,
     *     without_order: int,
     *     bank_linked: int,
     *     missing_order_examples: list<string>
     * }
     */
    public function import(string $path, bool $linkBank = true): array
    {
        $rows = $this->loadRows($path);
        $uploadedBy = User::query()->orderBy('id')->value('id');

        if ($uploadedBy === null) {
            throw new InvalidArgumentException('Cannot import invoices: no users exist to set as uploaded_by.');
        }

        $stats = [
            'rows' => count($rows),
            'created' => 0,
            'skipped_existing' => 0,
            'linked_orders' => 0,
            'without_order' => 0,
            'bank_linked' => 0,
            'missing_order_examples' => [],
        ];

        $ordersByKey = $this->historicalOrdersByKey();
        $usedOrderIds = Invoice::query()
            ->whereNotNull('order_id')
            ->pluck('order_id')
            ->map(fn ($id): int => (int) $id)
            ->flip()
            ->all();

        DB::transaction(function () use ($rows, $uploadedBy, $ordersByKey, &$usedOrderIds, &$stats): void {
            foreach ($rows as $row) {
                $existing = Invoice::query()
                    ->where('invoice_number', $row['invoice_number'])
                    ->first();

                if ($existing !== null) {
                    $stats['skipped_existing']++;
                    $this->ensureBuyerContact($existing, $row, $ordersByKey, $usedOrderIds);

                    continue;
                }

                $order = $this->resolveOrder($row, $ordersByKey, $usedOrderIds);
                $orderId = $order?->id;

                if ($orderId !== null) {
                    $usedOrderIds[(int) $orderId] = true;
                    $stats['linked_orders']++;
                } else {
                    $stats['without_order']++;
                    if (count($stats['missing_order_examples']) < 15) {
                        $stats['missing_order_examples'][] = sprintf(
                            '%s %s|%s €%s',
                            $row['invoice_number'],
                            $row['customer_code'],
                            $row['invoice_date'],
                            number_format($row['sum_inc_vat'], 2, '.', ''),
                        );
                    }
                }

                $customer = $order?->user;
                $vatRateId = $customer instanceof User
                    ? VatRateResolver::forUser($customer)?->id
                    : null;

                $buyerContactId = $this->buyerContactId($customer, $row);

                $invoice = Invoice::query()->create([
                    'order_id' => $orderId,
                    'contact_id' => $buyerContactId,
                    'invoice_series_id' => null,
                    'series_number' => null,
                    'invoice_number' => $row['invoice_number'],
                    'invoice_date' => $row['invoice_date'],
                    'sum_without_vat' => $row['sum_without_vat'],
                    'vat' => $row['vat'],
                    'sum_inc_vat' => $row['sum_inc_vat'],
                    'vat_rate_id' => $vatRateId,
                    'payment_term' => Invoice::paymentTermFromCustomer($customer),
                    'paid_amount' => 0,
                    'paid' => false,
                    'income_type_id' => Invoice::goodsIncomeTypeId(),
                    'expense_type_id' => null,
                    'upload_date' => $row['invoice_date'],
                    'uploaded_by' => $uploadedBy,
                    'pdf_path' => null,
                    'file_name' => null,
                    'file_mime' => null,
                    'file_content' => null,
                ]);

                InvoiceDefaultFinanceLines::apply($invoice);

                $stats['created']++;
            }
        });

        if ($linkBank) {
            $stats['bank_linked'] = $this->linkBankPayments();
        }

        return $stats;
    }

    /**
     * @return list<array{
     *     customer_code: string,
     *     customer_name: string,
     *     invoice_number: string,
     *     invoice_date: string,
     *     sum_without_vat: float,
     *     vat: float,
     *     sum_inc_vat: float
     * }>
     */
    public function loadRows(string $path): array
    {
        if (! is_file($path)) {
            throw new InvalidArgumentException('Sales journal file not found: '.$path);
        }

        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $sheet = $reader->load($path)->getSheet(0);
        $maxRow = (int) $sheet->getHighestRow();

        $rows = [];

        for ($r = 1; $r <= $maxRow; $r++) {
            $doc = strtoupper(trim((string) $sheet->getCell('I'.$r)->getValue()));
            if ($doc === '' || ! preg_match('/^FTE\d+$/', $doc)) {
                continue;
            }

            $code = strtoupper(trim((string) $sheet->getCell('D'.$r)->getValue()));
            $name = trim((string) $sheet->getCell('H'.$r)->getValue());
            $dateRaw = $sheet->getCell('K'.$r)->getValue();
            $net = round((float) $sheet->getCell('M'.$r)->getValue(), 2);
            $vat = round((float) $sheet->getCell('P'.$r)->getValue(), 2);
            $gross = round((float) $sheet->getCell('S'.$r)->getValue(), 2);

            $date = $this->excelDate($dateRaw);
            if ($code === '' || $date === null) {
                continue;
            }

            if ($gross <= 0 && abs($net + $vat - $gross) > 0.02) {
                $gross = round($net + $vat, 2);
            }

            $rows[] = [
                'customer_code' => $code,
                'customer_name' => $name,
                'invoice_number' => $doc,
                'invoice_date' => $date,
                'sum_without_vat' => $net,
                'vat' => $vat,
                'sum_inc_vat' => $gross,
            ];
        }

        usort($rows, function (array $a, array $b): int {
            return [$a['invoice_date'], $a['invoice_number']] <=> [$b['invoice_date'], $b['invoice_number']];
        });

        return $rows;
    }

    public function linkBankPayments(): int
    {
        return BankStatementInvoiceMatcher::autoLinkUnlinked();
    }

    /**
     * @return array<string, Order>
     */
    private function historicalOrdersByKey(): array
    {
        $map = [];

        Order::query()
            ->with('user')
            ->where('name', 'like', self::HISTORY_NAME_PREFIX.'%')
            ->orderBy('id')
            ->get()
            ->each(function (Order $order) use (&$map): void {
                $parts = explode('|', (string) $order->name);
                $code = strtoupper((string) ($parts[1] ?? ''));
                $date = (string) ($parts[2] ?? optional($order->order_date)?->toDateString());
                if ($code === '' || $date === '') {
                    return;
                }
                $map[$code.'|'.$date] = $order;
            });

        return $map;
    }

    /**
     * @param  array{customer_code: string, invoice_date: string}  $row
     * @param  array<string, Order>  $ordersByKey
     * @param  array<int, true>  $usedOrderIds
     */
    private function resolveOrder(array $row, array $ordersByKey, array $usedOrderIds): ?Order
    {
        $key = $row['customer_code'].'|'.$row['invoice_date'];
        $order = $ordersByKey[$key] ?? null;

        if ($order === null) {
            return null;
        }

        if (isset($usedOrderIds[(int) $order->id])) {
            return null;
        }

        return $order;
    }

    /**
     * @param  array{customer_code: string, customer_name: string, invoice_date: string}  $row
     * @param  array<string, Order>  $ordersByKey
     * @param  array<int, true>  $usedOrderIds
     */
    private function ensureBuyerContact(Invoice $invoice, array $row, array $ordersByKey, array $usedOrderIds): void
    {
        $issuerId = CompanySetting::instance()->syncContact()->id;
        if ((int) $invoice->contact_id !== (int) $issuerId) {
            return;
        }

        $customer = $invoice->order?->user;
        if (! $customer instanceof User) {
            $order = $this->resolveOrder($row, $ordersByKey, $usedOrderIds);
            $customer = $order?->user;
        }

        $buyerId = $this->buyerContactId($customer, $row);
        if ((int) $invoice->contact_id === (int) $buyerId) {
            return;
        }

        $invoice->forceFill(['contact_id' => $buyerId])->save();
    }

    /**
     * @param  array{customer_code: string, customer_name: string}  $row
     */
    private function buyerContactId(?User $customer, array $row): int
    {
        if ($customer instanceof User) {
            return Contact::syncFromCustomer($customer)->id;
        }

        return Contact::syncFromCustomerDetails($row['customer_name'], $row['customer_code'])->id;
    }

    private function excelDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
