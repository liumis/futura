<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Invoice;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class InvoiceSepaExporter
{
    public function __construct(
        protected SepaPain001Exporter $sepa,
    ) {}

    /**
     * @param  Collection<int, Invoice>  $invoices
     * @param  array<int, float>  $amountsByInvoiceId  Optional per-invoice payment amounts (partial/full). Defaults to outstanding.
     */
    public function buildXml(
        Collection $invoices,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
        array $amountsByInvoiceId = [],
    ): string {
        if ($invoices->isEmpty()) {
            throw new RuntimeException('Select at least one invoice.');
        }

        $transactions = [];

        foreach ($invoices as $invoice) {
            $invoice->loadMissing('contact');
            $contact = $invoice->contact;
            $invoiceId = (int) $invoice->getKey();

            if ($contact === null) {
                throw new RuntimeException('Invoice #'.$invoiceId.' has no company.');
            }

            $name = trim((string) $contact->company_name);

            if ($name === '') {
                throw new RuntimeException('Company name is missing for invoice #'.$invoiceId.'.');
            }

            $iban = $this->sepa->normalizeIban((string) ($contact->bank_account ?? ''));

            if ($iban === '') {
                throw new RuntimeException(
                    'Bank account is missing for '.$name.'. Set it under Finances → Contacts.',
                );
            }

            if (! $this->sepa->isValidIban($iban)) {
                throw new RuntimeException('Bank account is invalid for '.$name.'.');
            }

            $outstanding = $invoice->outstandingAmount();
            $amount = array_key_exists($invoiceId, $amountsByInvoiceId)
                ? round((float) $amountsByInvoiceId[$invoiceId], 2)
                : $outstanding;

            if ($amount <= 0) {
                throw new RuntimeException(
                    'Payment amount must be greater than zero for invoice '.$this->invoiceLabel($invoice).'.',
                );
            }

            if ($outstanding <= 0) {
                throw new RuntimeException(
                    'Nothing left to pay on invoice '.$this->invoiceLabel($invoice).'.',
                );
            }

            if ($amount > $outstanding + 0.001) {
                throw new RuntimeException(
                    'Amount '.$amount.' exceeds outstanding '.$outstanding.' on invoice '.$this->invoiceLabel($invoice).'.',
                );
            }

            $transactions[] = [
                'id' => $invoiceId,
                'name' => $name,
                'iban' => $iban,
                'bic' => $this->sepa->normalizeBic((string) ($contact->bank_bic ?? '')),
                'amount' => $amount,
                'remittance' => $this->remittance($invoice),
            ];
        }

        return $this->sepa->buildXmlFromTransactions(
            $transactions,
            $company,
            $executionDate,
            'INV',
        );
    }

    public function remittance(Invoice $invoice): string
    {
        $number = trim((string) ($invoice->invoice_number ?? ''));

        if ($number !== '') {
            return $number;
        }

        return 'Invoice '.$invoice->getKey();
    }

    public function invoiceLabel(Invoice $invoice): string
    {
        $number = trim((string) ($invoice->invoice_number ?? ''));

        return $number !== '' ? $number : '#'.$invoice->getKey();
    }
}
