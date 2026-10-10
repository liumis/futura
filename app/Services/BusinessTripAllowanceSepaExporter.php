<?php

namespace App\Services;

use App\Models\BusinessTripRequest;
use App\Models\CompanySetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class BusinessTripAllowanceSepaExporter
{
    public function __construct(
        protected SepaPain001Exporter $sepa,
    ) {}

    /**
     * @param  Collection<int, BusinessTripRequest>  $requests
     */
    public function buildXml(
        Collection $requests,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
    ): string {
        if ($requests->isEmpty()) {
            throw new RuntimeException('Select at least one business trip request.');
        }

        $transactions = [];

        foreach ($requests as $request) {
            $request->loadMissing('employee');
            $employee = $request->employee;

            if ($employee === null) {
                throw new RuntimeException('Employee is missing for business trip #'.$request->getKey().'.');
            }

            $name = $employee->fullName();
            if ($name === '') {
                throw new RuntimeException('Employee name is missing for business trip #'.$request->getKey().'.');
            }

            $iban = $this->sepa->normalizeIban((string) ($employee->bank_account ?? ''));
            if ($iban === '') {
                throw new RuntimeException(
                    'Bank account is missing for '.$name.'. Set it on the employee record.',
                );
            }

            if (! $this->sepa->isValidIban($iban)) {
                throw new RuntimeException('Bank account is invalid for '.$name.'.');
            }

            $amount = $request->payableAmount();
            if ($amount <= 0) {
                throw new RuntimeException(
                    'Payable amount must be greater than zero for business trip #'.$request->getKey().'.',
                );
            }

            $transactions[] = [
                'id' => (int) $request->getKey(),
                'name' => $name,
                'iban' => $iban,
                'bic' => '',
                'amount' => $amount,
                'remittance' => $this->remittance($request),
            ];
        }

        return $this->sepa->buildXmlFromTransactions(
            $transactions,
            $company,
            $executionDate,
            'DNG',
        );
    }

    public function remittance(BusinessTripRequest $request): string
    {
        $from = optional($request->date_from)?->format('Y-m-d') ?? '';
        $to = optional($request->date_to)?->format('Y-m-d') ?? '';
        $country = trim((string) $request->country_name);
        $base = trim('Dienpinigiai '.$country.' '.$from.($from !== $to ? '–'.$to : ''));

        $gpm = round((float) ($request->gpm_amount ?? 0), 2);
        if ($gpm > 0) {
            $base .= ' GPM '.$gpm;
        }

        return $base;
    }
}
