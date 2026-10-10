<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\ImportTaxPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class ImportTaxPaymentSepaExporter
{
    public function __construct(
        protected SepaPain001Exporter $sepa,
    ) {}

    /**
     * @param  Collection<int, ImportTaxPayment>  $payments
     */
    public function buildXml(
        Collection $payments,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
    ): string {
        if ($payments->isEmpty()) {
            throw new RuntimeException('Select at least one import tax payment.');
        }

        $transactions = [];

        foreach ($payments as $payment) {
            $payment->loadMissing(['customsContact', 'cargo']);
            $contact = $payment->customsContact;

            if ($contact === null) {
                throw new RuntimeException(
                    'Select a customs company for warehouse order #'.$payment->cargo_id.'.',
                );
            }

            $name = trim((string) $contact->company_name);

            if ($name === '') {
                throw new RuntimeException('Customs company name is missing.');
            }

            $iban = $this->sepa->normalizeIban((string) ($contact->bank_account ?? ''));

            if ($iban === '') {
                throw new RuntimeException(
                    'Bank account is missing for '.$name.'. Set it under Financial options → Customs contacts.',
                );
            }

            if (! $this->sepa->isValidIban($iban)) {
                throw new RuntimeException('Bank account is invalid for '.$name.'.');
            }

            $amount = $payment->totalCost();

            if ($amount <= 0) {
                throw new RuntimeException(
                    'Total cost must be greater than zero for warehouse order #'.$payment->cargo_id.'.',
                );
            }

            $transactions[] = [
                'id' => (int) $payment->getKey(),
                'name' => $name,
                'iban' => $iban,
                'bic' => $this->sepa->normalizeBic((string) ($contact->bank_bic ?? '')),
                'amount' => $amount,
                'remittance' => $this->remittance($payment),
            ];
        }

        return $this->sepa->buildXmlFromTransactions(
            $transactions,
            $company,
            $executionDate,
            'ITX',
        );
    }

    public function remittance(ImportTaxPayment $payment): string
    {
        $parts = ['Import tax'];

        if ((float) $payment->service_cost > 0) {
            $parts[] = 'and service';
        }

        $parts[] = 'WO '.$payment->cargo_id;

        return implode(' ', $parts);
    }
}
