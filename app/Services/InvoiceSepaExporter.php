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
     */
    public function buildXml(
        Collection $invoices,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
    ): string {
        if ($invoices->isEmpty()) {
            throw new RuntimeException('Select at least one invoice.');
        }

        $transactions = [];

        foreach ($invoices as $invoice) {
            $invoice->loadMissing('contact');
            $contact = $invoice->contact;

            if ($contact === null) {
                throw new RuntimeException('Invoice #'.$invoice->getKey().' has no company.');
            }

            $name = trim((string) $contact->company_name);

            if ($name === '') {
                throw new RuntimeException('Company name is missing for invoice #'.$invoice->getKey().'.');
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

            $amount = $invoice->outstandingAmount();

            if ($amount <= 0) {
                throw new RuntimeException(
                    'Nothing left to pay on invoice '.$this->invoiceLabel($invoice).'.',
                );
            }

            $transactions[] = [
                'id' => (int) $invoice->getKey(),
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
