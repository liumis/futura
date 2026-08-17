<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeeMonthlyPayment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

class PayrollAuthoritySepaExporter
{
    public const VMI_PAYMENT_CODE = '1311';

    public const SODRA_PAYMENT_CODE = '252';

    public const VMI_CREDITOR_NAME = 'VALSTYBINE MOKESCIU INSPEKCIJA';

    public const SODRA_CREDITOR_NAME = 'SODRA';

    /** SEB collection account commonly used for VMI (verify on vmi.lt). */
    public const DEFAULT_VMI_IBAN = 'LT057044060007887175';

    /** SEB collection account for Sodra (verify on sodra.lt). */
    public const DEFAULT_SODRA_IBAN = 'LT337044060007740589';

    public function __construct(
        protected SepaPain001Exporter $sepa,
    ) {}

    /**
     * @param  Collection<int, EmployeeMonthlyPayment>  $payments
     */
    public function buildVmiXml(
        Collection $payments,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
    ): string {
        $company ??= CompanySetting::instance();
        $executionDate ??= now()->startOfDay();

        $amount = round($payments->sum(fn ($p): float => (float) ($p->gpm_amount ?? 0)), 2);
        if ($amount <= 0) {
            throw new RuntimeException('Total GPM is zero. Save payments first so tax is calculated.');
        }

        $iban = $this->sepa->normalizeIban((string) ($company->vmi_iban ?: self::DEFAULT_VMI_IBAN));
        $this->assertIban($iban, 'VMI');

        $companyCode = trim((string) ($company->company_id ?? ''));
        $period = $this->periodLabel($payments);
        $remittance = trim(self::VMI_PAYMENT_CODE.' '.($companyCode !== '' ? $companyCode.' ' : '').'GPM '.$period);

        return $this->sepa->buildXmlFromTransactions(
            [[
                'id' => 1,
                'name' => self::VMI_CREDITOR_NAME,
                'iban' => $iban,
                'bic' => $this->sepa->normalizeBic((string) ($company->vmi_bic ?? '')),
                'amount' => $amount,
                'remittance' => $remittance,
            ]],
            $company,
            $executionDate,
            'VMI',
        );
    }

    /**
     * @param  Collection<int, EmployeeMonthlyPayment>  $payments
     */
    public function buildSodraXml(
        Collection $payments,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
    ): string {
        $company ??= CompanySetting::instance();
        $executionDate ??= now()->startOfDay();

        $employeeSodra = round($payments->sum(fn ($p): float => (float) ($p->sodra_employee_amount ?? 0)), 2);
        $employerSodra = round($payments->sum(fn ($p): float => (float) ($p->sodra_employer_amount ?? 0)), 2);
        $amount = round($employeeSodra + $employerSodra, 2);

        if ($amount <= 0) {
            throw new RuntimeException('Total Sodra is zero. Save payments first so tax is calculated.');
        }

        if ($employerSodra <= 0 && $payments->contains(fn ($p): bool => $p->sodra_employer_amount === null)) {
            throw new RuntimeException(
                'Employer Sodra is missing on selected payments. Re-save open payments to recalculate tax.',
            );
        }

        $iban = $this->sepa->normalizeIban((string) ($company->sodra_iban ?: self::DEFAULT_SODRA_IBAN));
        $this->assertIban($iban, 'Sodra');

        $companyCode = trim((string) ($company->company_id ?? ''));
        $period = $this->periodLabel($payments);
        $remittance = trim(self::SODRA_PAYMENT_CODE.' '.($companyCode !== '' ? $companyCode.' ' : '').'SODRA '.$period);

        return $this->sepa->buildXmlFromTransactions(
            [[
                'id' => 1,
                'name' => self::SODRA_CREDITOR_NAME,
                'iban' => $iban,
                'bic' => $this->sepa->normalizeBic((string) ($company->sodra_bic ?? '')),
                'amount' => $amount,
                'remittance' => $remittance,
            ]],
            $company,
            $executionDate,
            'SDR',
        );
    }

    protected function assertIban(string $iban, string $label): void
    {
        if ($iban === '') {
            throw new RuntimeException($label.' IBAN is missing. Set it under Company details on Payments.');
        }

        if (! $this->sepa->isValidIban($iban)) {
            throw new RuntimeException($label.' IBAN is invalid.');
        }
    }

    /**
     * @param  Collection<int, EmployeeMonthlyPayment>  $payments
     */
    protected function periodLabel(Collection $payments): string
    {
        $dates = $payments
            ->map(fn ($p): ?string => $p->payment_date?->format('Y-m'))
            ->filter()
            ->unique()
            ->values();

        if ($dates->count() === 1) {
            return (string) $dates->first();
        }

        return now()->format('Y-m');
    }
}
