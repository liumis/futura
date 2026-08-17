<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeeMonthlyPayment;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

class SepaPain001Exporter
{
    private const NS = 'urn:iso:std:iso:20022:tech:xsd:pain.001.001.09';

    /**
     * Build a SEB-compatible ISO 20022 pain.001.001.09 credit transfer file.
     *
     * @param  Collection<int, EmployeeMonthlyPayment>  $payments
     */
    public function buildXml(
        Collection $payments,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
    ): string {
        $company ??= CompanySetting::instance();
        $executionDate ??= now()->startOfDay();

        $debtorName = trim((string) ($company->company_name ?? ''));
        $debtorIban = $this->normalizeIban((string) ($company->company_iban ?? ''));
        $debtorBic = $this->normalizeBic((string) ($company->company_bic ?? ''));

        if ($debtorName === '') {
            throw new RuntimeException('Company name is missing. Set it under System → My company.');
        }

        if ($debtorIban === '') {
            throw new RuntimeException('Company IBAN is missing. Set it under System → My company.');
        }

        if (! $this->isValidIban($debtorIban)) {
            throw new RuntimeException('Company IBAN is invalid.');
        }

        $transactions = [];
        $controlSum = 0.0;

        foreach ($payments as $payment) {
            $employee = $payment->employee;
            if ($employee === null) {
                throw new RuntimeException('Payment #'.$payment->getKey().' has no employee.');
            }

            $creditorName = trim($employee->fullName());
            $creditorIban = $this->normalizeIban((string) ($employee->bank_account ?? ''));

            if ($creditorName === '') {
                throw new RuntimeException('Employee name is missing for payment #'.$payment->getKey().'.');
            }

            if ($creditorIban === '') {
                throw new RuntimeException('Bank account is missing for '.$creditorName.'.');
            }

            if (! $this->isValidIban($creditorIban)) {
                throw new RuntimeException('Bank account is invalid for '.$creditorName.'.');
            }

            $amount = $payment->net_amount !== null
                ? round((float) $payment->net_amount, 2)
                : 0.0;

            if ($amount <= 0) {
                throw new RuntimeException(
                    'Net amount is missing or zero for '.$creditorName.'. Save the open payment first so tax is calculated.',
                );
            }

            $controlSum += $amount;
            $dateLabel = $payment->payment_date?->format('Y-m-d') ?? $executionDate->toDateString();
            $remittance = filled($payment->comment)
                ? (string) $payment->comment
                : ($payment instanceof \App\Models\Dividend
                    ? 'Dividend '.$dateLabel
                    : 'Salary '.$dateLabel);

            $transactions[] = [
                'id' => (int) $payment->getKey(),
                'name' => $creditorName,
                'iban' => $creditorIban,
                'amount' => $amount,
                'remittance' => $this->truncate($remittance, 140),
            ];
        }

        if ($transactions === []) {
            throw new RuntimeException('No payments selected for export.');
        }

        return $this->buildXmlFromTransactions($transactions, $company, $executionDate, 'SAL');
    }

    /**
     * @param  list<array{id: int, name: string, iban: string, amount: float, remittance: string, bic?: string}>  $transactions
     */
    public function buildXmlFromTransactions(
        array $transactions,
        ?CompanySetting $company = null,
        ?Carbon $executionDate = null,
        string $msgPrefix = 'PMT',
    ): string {
        $company ??= CompanySetting::instance();
        $executionDate ??= now()->startOfDay();

        $debtorName = trim((string) ($company->company_name ?? ''));
        $debtorIban = $this->normalizeIban((string) ($company->company_iban ?? ''));
        $debtorBic = $this->normalizeBic((string) ($company->company_bic ?? ''));

        if ($debtorName === '') {
            throw new RuntimeException('Company name is missing. Set it under System → My company.');
        }

        if ($debtorIban === '') {
            throw new RuntimeException('Company IBAN is missing. Set it under System → My company.');
        }

        if (! $this->isValidIban($debtorIban)) {
            throw new RuntimeException('Company IBAN is invalid.');
        }

        if ($transactions === []) {
            throw new RuntimeException('No payments selected for export.');
        }

        $controlSum = 0.0;
        foreach ($transactions as $tx) {
            $amount = round((float) $tx['amount'], 2);
            if ($amount <= 0) {
                throw new RuntimeException('Amount must be greater than zero.');
            }
            $controlSum += $amount;
        }

        $nbOfTxs = (string) count($transactions);
        $ctrlSum = number_format($controlSum, 2, '.', '');
        $msgId = $this->truncate($msgPrefix.$executionDate->format('YmdHis').Str::upper(Str::random(6)), 35);
        $pmtInfId = $this->truncate('PMT'.$executionDate->format('YmdHis'), 35);
        $currency = Money::currency();
        $createdAt = now()->format('Y-m-d\TH:i:s');

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $document = $this->el($xml, 'Document');
        $xml->appendChild($document);

        $root = $this->el($xml, 'CstmrCdtTrfInitn');
        $document->appendChild($root);

        $grpHdr = $this->el($xml, 'GrpHdr');
        $root->appendChild($grpHdr);
        $this->appendText($xml, $grpHdr, 'MsgId', $msgId);
        $this->appendText($xml, $grpHdr, 'CreDtTm', $createdAt);
        $this->appendText($xml, $grpHdr, 'NbOfTxs', $nbOfTxs);
        $this->appendText($xml, $grpHdr, 'CtrlSum', $ctrlSum);
        $initgPty = $this->el($xml, 'InitgPty');
        $grpHdr->appendChild($initgPty);
        $this->appendText($xml, $initgPty, 'Nm', $this->xmlText($debtorName));

        $pmtInf = $this->el($xml, 'PmtInf');
        $root->appendChild($pmtInf);
        $this->appendText($xml, $pmtInf, 'PmtInfId', $pmtInfId);
        $this->appendText($xml, $pmtInf, 'PmtMtd', 'TRF');
        $this->appendText($xml, $pmtInf, 'BtchBookg', 'true');
        $this->appendText($xml, $pmtInf, 'NbOfTxs', $nbOfTxs);
        $this->appendText($xml, $pmtInf, 'CtrlSum', $ctrlSum);

        $pmtTpInf = $this->el($xml, 'PmtTpInf');
        $pmtInf->appendChild($pmtTpInf);
        $svcLvl = $this->el($xml, 'SvcLvl');
        $pmtTpInf->appendChild($svcLvl);
        $this->appendText($xml, $svcLvl, 'Cd', 'SEPA');

        $reqdExctnDt = $this->el($xml, 'ReqdExctnDt');
        $pmtInf->appendChild($reqdExctnDt);
        $this->appendText($xml, $reqdExctnDt, 'Dt', $executionDate->toDateString());

        $dbtr = $this->el($xml, 'Dbtr');
        $pmtInf->appendChild($dbtr);
        $this->appendText($xml, $dbtr, 'Nm', $this->xmlText($debtorName));

        $dbtrAcct = $this->el($xml, 'DbtrAcct');
        $pmtInf->appendChild($dbtrAcct);
        $dbtrAcctId = $this->el($xml, 'Id');
        $dbtrAcct->appendChild($dbtrAcctId);
        $this->appendText($xml, $dbtrAcctId, 'IBAN', $debtorIban);
        $this->appendText($xml, $dbtrAcct, 'Ccy', $currency);

        if ($debtorBic !== '') {
            $dbtrAgt = $this->el($xml, 'DbtrAgt');
            $pmtInf->appendChild($dbtrAgt);
            $finInstnId = $this->el($xml, 'FinInstnId');
            $dbtrAgt->appendChild($finInstnId);
            $this->appendText($xml, $finInstnId, 'BICFI', $debtorBic);
        }

        $this->appendText($xml, $pmtInf, 'ChrgBr', 'SLEV');

        foreach ($transactions as $index => $tx) {
            $cdtTrfTxInf = $this->el($xml, 'CdtTrfTxInf');
            $pmtInf->appendChild($cdtTrfTxInf);

            $instrId = $this->truncate('I'.$tx['id'].'-'.($index + 1), 35);
            $endToEndId = $this->truncate('E2E'.$tx['id'], 35);

            $pmtId = $this->el($xml, 'PmtId');
            $cdtTrfTxInf->appendChild($pmtId);
            $this->appendText($xml, $pmtId, 'InstrId', $instrId);
            $this->appendText($xml, $pmtId, 'EndToEndId', $endToEndId);

            $amt = $this->el($xml, 'Amt');
            $cdtTrfTxInf->appendChild($amt);
            $instdAmt = $this->el($xml, 'InstdAmt', number_format((float) $tx['amount'], 2, '.', ''));
            $instdAmt->setAttribute('Ccy', $currency);
            $amt->appendChild($instdAmt);

            $creditorBic = $this->normalizeBic((string) ($tx['bic'] ?? ''));
            if ($creditorBic !== '') {
                $cdtrAgt = $this->el($xml, 'CdtrAgt');
                $cdtTrfTxInf->appendChild($cdtrAgt);
                $cdtrFinInstnId = $this->el($xml, 'FinInstnId');
                $cdtrAgt->appendChild($cdtrFinInstnId);
                $this->appendText($xml, $cdtrFinInstnId, 'BICFI', $creditorBic);
            }

            $cdtr = $this->el($xml, 'Cdtr');
            $cdtTrfTxInf->appendChild($cdtr);
            $this->appendText($xml, $cdtr, 'Nm', $this->xmlText($tx['name']));

            $cdtrAcct = $this->el($xml, 'CdtrAcct');
            $cdtTrfTxInf->appendChild($cdtrAcct);
            $cdtrAcctId = $this->el($xml, 'Id');
            $cdtrAcct->appendChild($cdtrAcctId);
            $this->appendText($xml, $cdtrAcctId, 'IBAN', $tx['iban']);

            $rmtInf = $this->el($xml, 'RmtInf');
            $cdtTrfTxInf->appendChild($rmtInf);
            $this->appendText($xml, $rmtInf, 'Ustrd', $this->xmlText($tx['remittance']));
        }

        $output = $xml->saveXML();

        if ($output === false) {
            throw new RuntimeException('Unable to generate SEPA XML.');
        }

        return $output;
    }

    public function normalizeIban(string $iban): string
    {
        return strtoupper(preg_replace('/\s+/', '', $iban) ?? '');
    }

    public function normalizeBic(string $bic): string
    {
        return strtoupper(preg_replace('/\s+/', '', $bic) ?? '');
    }

    public function isValidIban(string $iban): bool
    {
        $iban = $this->normalizeIban($iban);

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $rearranged = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        $checksum = $numeric;
        while (strlen($checksum) > 2) {
            $block = substr($checksum, 0, 9);
            $checksum = ((int) $block % 97).substr($checksum, strlen($block));
        }

        return ((int) $checksum % 97) === 1;
    }

    protected function el(\DOMDocument $xml, string $name, ?string $value = null): \DOMElement
    {
        if ($value === null) {
            return $xml->createElementNS(self::NS, $name);
        }

        $element = $xml->createElementNS(self::NS, $name);
        $element->appendChild($xml->createTextNode($value));

        return $element;
    }

    protected function appendText(\DOMDocument $xml, \DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($this->el($xml, $name, $value));
    }

    protected function truncate(string $value, int $max): string
    {
        return Str::limit($value, $max, '');
    }

    protected function xmlText(string $value): string
    {
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        // SEPA restricted character set: keep Latin letters, digits, and common punctuation.
        return preg_replace("/[^a-zA-Z0-9 \\/\\-\\?:\\(\\)\\.,'\\+]/u", '', $value) ?? '';
    }
}
