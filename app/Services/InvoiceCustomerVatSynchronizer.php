<?php

namespace App\Services;

use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;
use Illuminate\Support\Str;
use RuntimeException;

class InvoiceCustomerVatSynchronizer
{
    /**
     * Apply the customer’s VAT rate to invoice amounts and regenerate the SharePoint PDF.
     *
     * @return array{updated: bool, vat_classificator: ?string, customer: ?string}
     */
    public function sync(Invoice $invoice, bool $refreshFinanceLines = true): array
    {
        if ($invoice->is_own_account) {
            throw new RuntimeException('Own-account transfers are not sales invoices.');
        }

        $invoice->loadMissing(['order.user.vatRate', 'order.orderItems', 'contact', 'vatRate']);
        $customer = $this->resolveCustomer($invoice);
        $vatRate = $customer !== null
            ? VatRateResolver::forUser($customer)
            : $this->fallbackVatRateFromContact($invoice);

        if ($vatRate === null) {
            throw new RuntimeException('No customer user / VAT rate found for invoice '.$invoice->displayNumber());
        }

        $net = $this->resolveNetAmount($invoice);
        [$sumWithoutVat, $vat, $sumIncVat] = VatRateResolver::calculateFromNet($net, $vatRate);

        $invoice->forceFill([
            'vat_rate_id' => $vatRate->id,
            'sum_without_vat' => $sumWithoutVat,
            'vat' => $vat,
            'sum_inc_vat' => $sumIncVat,
        ])->save();

        $invoice = $invoice->fresh(['order.user', 'contact', 'vatRate']) ?? $invoice;

        if ($refreshFinanceLines) {
            InvoiceDefaultFinanceLines::apply($invoice, force: true);
        }

        InvoicePdfGenerator::generateAndStoreOnSharepoint($invoice);

        $customerLabel = $customer?->company_name
            ?: $customer?->fullName()
            ?: $invoice->contact?->company_name
            ?: 'Unknown customer';

        return [
            'updated' => true,
            'vat_classificator' => $vatRate->classificator,
            'customer' => $customerLabel,
        ];
    }

    public function resolveCustomer(Invoice $invoice): ?User
    {
        $invoice->loadMissing(['order.user', 'contact']);

        if ($invoice->order?->user instanceof User) {
            return $invoice->order->user;
        }

        $contact = $invoice->contact;
        if ($contact === null) {
            return null;
        }

        $customers = User::query()->role(User::ROLE_CUSTOMER)->get();

        $code = $this->normalizeKey((string) ($contact->company_id ?? ''));
        if ($code !== '') {
            $match = $customers->first(function (User $user) use ($code): bool {
                return $this->normalizeKey((string) ($user->company_code ?? '')) === $code;
            });
            if ($match) {
                return $match;
            }
        }

        $name = $this->normalizeKey((string) ($contact->company_name ?? ''));
        if ($name !== '') {
            $match = $customers->first(function (User $user) use ($name): bool {
                $userName = $this->normalizeKey((string) ($user->company_name ?? ''));

                return $userName !== '' && ($userName === $name
                    || str_contains($userName, $name)
                    || str_contains($name, $userName));
            });
            if ($match) {
                return $match;
            }
        }

        return null;
    }

    private function fallbackVatRateFromContact(Invoice $invoice): ?VatRate
    {
        $hay = Str::lower(trim(implode(' ', array_filter([
            (string) ($invoice->contact?->company_country ?? ''),
            (string) ($invoice->contact?->company_address ?? ''),
            (string) ($invoice->contact?->company_name ?? ''),
        ]))));

        $isLt = $hay !== '' && (
            str_contains($hay, 'lithuania')
            || str_contains($hay, 'lietuva')
            || preg_match('/\buab\b/', $hay) === 1
        );

        return VatRate::query()
            ->where('classificator', $isLt ? 'PVM1' : 'PVM4')
            ->first();
    }

    private function resolveNetAmount(Invoice $invoice): float
    {
        $number = strtoupper(trim((string) ($invoice->invoice_number ?? '')));

        // Historical Futura journal: keep recorded net, only re-apply customer VAT.
        if (str_starts_with($number, 'FTE')) {
            return round((float) $invoice->sum_without_vat, 2);
        }

        $order = $invoice->order;

        if ($order !== null) {
            OrderResource::recalculateOrderAmount($order->fresh(['user', 'orderItems.product']));
            $order = $order->fresh();
            $amount = round((float) ($order?->amount ?? 0), 2);
            if ($amount > 0.004) {
                return $amount;
            }
        }

        return round((float) $invoice->sum_without_vat, 2);
    }

    private function normalizeKey(string $value): string
    {
        $value = Str::upper(Str::ascii(trim($value)));

        return preg_replace('/[^A-Z0-9]/', '', $value) ?? '';
    }
}
