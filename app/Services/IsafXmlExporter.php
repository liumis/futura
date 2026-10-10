<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\VatRate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Builds an i.SAF 1.2 XML invoice-register file for upload to i.MAS.
 *
 * @see https://www.vmi.lt/evmi/i.saf2
 */
class IsafXmlExporter
{
    public const NS = 'http://www.vmi.lt/cms/imas/isaf';

    public const XSI_NS = 'http://www.w3.org/2001/XMLSchema-instance';

    public const FILE_VERSION = 'iSAF1.2';

    public const DATA_FULL = 'F';

    public const DATA_PURCHASES = 'P';

    public const DATA_SALES = 'S';

    public const INVOICE_TYPE_STANDARD = 'SF';

    /**
     * @var array<string, string>
     */
    private const COUNTRY_ISO = [
        'Albania' => 'AL',
        'Andorra' => 'AD',
        'Armenia' => 'AM',
        'Austria' => 'AT',
        'Azerbaijan' => 'AZ',
        'Belarus' => 'BY',
        'Belgium' => 'BE',
        'Bosnia and Herzegovina' => 'BA',
        'Bulgaria' => 'BG',
        'Croatia' => 'HR',
        'Cyprus' => 'CY',
        'Czech Republic' => 'CZ',
        'Denmark' => 'DK',
        'Estonia' => 'EE',
        'Finland' => 'FI',
        'France' => 'FR',
        'Georgia' => 'GE',
        'Germany' => 'DE',
        'Greece' => 'GR',
        'Hungary' => 'HU',
        'Iceland' => 'IS',
        'India' => 'IN',
        'Ireland' => 'IE',
        'Italy' => 'IT',
        'Kazakhstan' => 'KZ',
        'Kosovo' => 'XK',
        'Latvia' => 'LV',
        'Liechtenstein' => 'LI',
        'Lithuania' => 'LT',
        'Lietuva' => 'LT',
        'Luxembourg' => 'LU',
        'Malta' => 'MT',
        'Moldova' => 'MD',
        'Monaco' => 'MC',
        'Montenegro' => 'ME',
        'Netherlands' => 'NL',
        'North Macedonia' => 'MK',
        'Norway' => 'NO',
        'Poland' => 'PL',
        'Portugal' => 'PT',
        'Romania' => 'RO',
        'San Marino' => 'SM',
        'Serbia' => 'RS',
        'Slovakia' => 'SK',
        'Slovenia' => 'SI',
        'Spain' => 'ES',
        'Sweden' => 'SE',
        'Switzerland' => 'CH',
        'Turkey' => 'TR',
        'Ukraine' => 'UA',
        'United Kingdom' => 'GB',
        'Vatican City' => 'VA',
    ];

    /**
     * @param  iterable<int, Invoice>  $invoices
     * @param  array{
     *     from?: ?string,
     *     until?: ?string,
     *     data_type?: string,
     *     part_number?: string,
     *     number_of_parts?: int
     * }  $filters
     */
    public function buildXml(iterable $invoices, array $filters = [], ?CompanySetting $company = null): string
    {
        $company ??= CompanySetting::instance();
        $registrationNumber = $this->taxpayerCode($company);

        if ($registrationNumber === '') {
            throw new RuntimeException('Company id is missing. Set it under System → My company. i.SAF requires RegistrationNumber.');
        }

        $from = $this->requiredDate($filters['from'] ?? null, 'From');
        $until = $this->requiredDate($filters['until'] ?? null, 'Until');
        if ($until->lt($from)) {
            throw new RuntimeException('Until date must be on or after From date.');
        }

        $dataType = $this->normalizeDataType($filters['data_type'] ?? self::DATA_FULL);
        $partNumber = $this->normalizePartNumber($filters['part_number'] ?? '1');
        $numberOfParts = max(1, (int) ($filters['number_of_parts'] ?? 1));

        $groups = $this->groupInvoices($invoices, $dataType);

        $xml = new \DOMDocument('1.0', 'UTF-8');
        $xml->formatOutput = true;

        $root = $this->el($xml, 'iSAFFile');
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns', self::NS);
        $root->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:xsi', self::XSI_NS);
        $xml->appendChild($root);

        $root->appendChild($this->buildHeader(
            $xml,
            $company,
            $registrationNumber,
            $dataType,
            $partNumber,
            $numberOfParts,
            $from,
            $until,
        ));

        $source = $this->buildSourceDocuments($xml, $groups['purchases'], $groups['sales'], $from, $until);
        if ($source !== null) {
            $root->appendChild($source);
        }

        $output = $xml->saveXML();
        if ($output === false) {
            throw new RuntimeException('Could not build i.SAF XML.');
        }

        return $output;
    }

    /**
     * @param  iterable<int, Invoice>  $invoices
     * @return array{
     *     sales: Collection<int, Invoice>,
     *     purchases: Collection<int, Invoice>,
     *     unclassified: Collection<int, Invoice>,
     *     warnings: list<string>
     * }
     */
    public function preview(iterable $invoices, string $dataType = self::DATA_FULL): array
    {
        $dataType = $this->normalizeDataType($dataType);
        $all = Collection::make($invoices);
        $sales = $all->filter(fn (Invoice $invoice): bool => PvmReportBuilder::direction($invoice) === PvmReportBuilder::DIRECTION_SALES)->values();
        $purchases = $all->filter(fn (Invoice $invoice): bool => PvmReportBuilder::direction($invoice) === PvmReportBuilder::DIRECTION_PURCHASES)->values();
        $unclassified = $all->filter(fn (Invoice $invoice): bool => PvmReportBuilder::direction($invoice) === PvmReportBuilder::DIRECTION_UNCLASSIFIED)->values();

        if ($dataType === self::DATA_SALES) {
            $purchases = collect();
        } elseif ($dataType === self::DATA_PURCHASES) {
            $sales = collect();
        }

        $warnings = [];
        foreach ($unclassified as $invoice) {
            $warnings[] = $invoice->displayNumber().' is unclassified (no income/expense type) and will not be included.';
        }

        foreach ($sales->concat($purchases) as $invoice) {
            $invoice->loadMissing(['contact', 'vatRate']);
            if ($this->partyVat($invoice->contact) === 'ND') {
                $warnings[] = $invoice->displayNumber().' has no VAT number — exported as ND.';
            }
            if ($this->taxCode($invoice->vatRate) === null) {
                $warnings[] = $invoice->displayNumber().' has no PVM classificator (PVM1, PVM2, …).';
            }
        }

        return [
            'sales' => $sales,
            'purchases' => $purchases,
            'unclassified' => $unclassified,
            'warnings' => $warnings,
        ];
    }

    public function fileName(array $filters = []): string
    {
        $type = strtolower($this->normalizeDataType($filters['data_type'] ?? self::DATA_FULL));
        $from = preg_replace('/[^0-9]/', '', (string) ($filters['from'] ?? '')) ?: 'all';
        $until = preg_replace('/[^0-9]/', '', (string) ($filters['until'] ?? '')) ?: 'all';

        return sprintf('isaf-%s-%s-%s.xml', $type, $from, $until);
    }

    public static function dataTypeLabel(string $dataType): string
    {
        return match ($dataType) {
            self::DATA_SALES => 'Sales (issued invoices)',
            self::DATA_PURCHASES => 'Purchases (received invoices)',
            default => 'Full (sales and purchases)',
        };
    }

    /**
     * @param  iterable<int, Invoice>  $invoices
     * @return array{sales: Collection<int, Invoice>, purchases: Collection<int, Invoice>}
     */
    private function groupInvoices(iterable $invoices, string $dataType): array
    {
        $preview = $this->preview($invoices, $dataType);

        return [
            'sales' => $preview['sales'],
            'purchases' => $preview['purchases'],
        ];
    }

    private function buildHeader(
        \DOMDocument $xml,
        CompanySetting $company,
        string $registrationNumber,
        string $dataType,
        string $partNumber,
        int $numberOfParts,
        Carbon $from,
        Carbon $until,
    ): \DOMElement {
        $header = $this->el($xml, 'Header');
        $file = $this->el($xml, 'FileDescription');
        $header->appendChild($file);

        $this->appendText($xml, $file, 'FileVersion', self::FILE_VERSION);
        $this->appendText($xml, $file, 'FileDateCreated', now()->format('Y-m-d\TH:i:s'));
        $this->appendText($xml, $file, 'DataType', $dataType);
        $this->appendText($xml, $file, 'SoftwareCompanyName', $this->truncate($this->xmlText((string) ($company->company_name ?: config('app.name'))), 255));
        $this->appendText($xml, $file, 'SoftwareName', $this->truncate($this->xmlText((string) config('app.name', 'FuturaTextiles SS')), 255));
        $this->appendText($xml, $file, 'SoftwareVersion', '1.0');
        $this->appendText($xml, $file, 'RegistrationNumber', $registrationNumber);
        $this->appendText($xml, $file, 'NumberOfParts', (string) $numberOfParts);
        $this->appendText($xml, $file, 'PartNumber', $partNumber);

        $criteria = $this->el($xml, 'SelectionCriteria');
        $file->appendChild($criteria);
        $this->appendText($xml, $criteria, 'SelectionStartDate', $from->toDateString());
        $this->appendText($xml, $criteria, 'SelectionEndDate', $until->toDateString());

        return $header;
    }

    /**
     * @param  Collection<int, Invoice>  $purchases
     * @param  Collection<int, Invoice>  $sales
     */
    private function buildSourceDocuments(
        \DOMDocument $xml,
        Collection $purchases,
        Collection $sales,
        Carbon $from,
        Carbon $until,
    ): ?\DOMElement {
        if ($purchases->isEmpty() && $sales->isEmpty()) {
            return null;
        }

        $source = $this->el($xml, 'SourceDocuments');

        if ($purchases->isNotEmpty()) {
            $list = $this->el($xml, 'PurchaseInvoices');
            $source->appendChild($list);
            foreach ($purchases as $invoice) {
                $list->appendChild($this->buildPurchaseInvoice($xml, $invoice, $from, $until));
            }
        }

        if ($sales->isNotEmpty()) {
            $list = $this->el($xml, 'SalesInvoices');
            $source->appendChild($list);
            foreach ($sales as $invoice) {
                $list->appendChild($this->buildSalesInvoice($xml, $invoice));
            }
        }

        return $source;
    }

    private function buildPurchaseInvoice(\DOMDocument $xml, Invoice $invoice, Carbon $from, Carbon $until): \DOMElement
    {
        $invoice->loadMissing(['contact', 'vatRate']);
        $elem = $this->el($xml, 'Invoice');
        $this->appendText($xml, $elem, 'InvoiceNo', $this->invoiceNo($invoice));
        $elem->appendChild($this->buildParty($xml, $invoice->contact, 'SupplierInfo'));
        $this->appendText($xml, $elem, 'InvoiceDate', $this->invoiceDate($invoice));
        $this->appendText($xml, $elem, 'InvoiceType', self::INVOICE_TYPE_STANDARD);
        $this->appendText($xml, $elem, 'SpecialTaxation', '');
        $elem->appendChild($this->el($xml, 'References'));
        $elem->appendChild($this->nil($xml, 'VATPointDate'));

        $registrationDate = $this->registrationAccountDate($invoice, $from, $until);
        if ($registrationDate === null) {
            $elem->appendChild($this->nil($xml, 'RegistrationAccountDate'));
        } else {
            $this->appendText($xml, $elem, 'RegistrationAccountDate', $registrationDate);
        }

        $elem->appendChild($this->buildDocumentTotals($xml, $invoice, includeVatPointDate2: false));

        return $elem;
    }

    private function buildSalesInvoice(\DOMDocument $xml, Invoice $invoice): \DOMElement
    {
        $invoice->loadMissing(['contact', 'vatRate']);
        $elem = $this->el($xml, 'Invoice');
        $this->appendText($xml, $elem, 'InvoiceNo', $this->invoiceNo($invoice));
        $elem->appendChild($this->buildParty($xml, $invoice->contact, 'CustomerInfo'));
        $this->appendText($xml, $elem, 'InvoiceDate', $this->invoiceDate($invoice));
        $this->appendText($xml, $elem, 'InvoiceType', self::INVOICE_TYPE_STANDARD);
        $this->appendText($xml, $elem, 'SpecialTaxation', '');
        $elem->appendChild($this->el($xml, 'References'));
        $elem->appendChild($this->nil($xml, 'VATPointDate'));
        $elem->appendChild($this->buildDocumentTotals($xml, $invoice, includeVatPointDate2: true));

        return $elem;
    }

    private function buildParty(\DOMDocument $xml, ?Contact $contact, string $tag): \DOMElement
    {
        $party = $this->el($xml, $tag);
        $vat = $this->partyVat($contact);
        $this->appendText($xml, $party, 'VATRegistrationNumber', $vat);

        if ($vat === 'ND') {
            $this->appendText($xml, $party, 'RegistrationNumber', $this->partyRegistration($contact));
            $country = $this->countryIso($contact?->company_country);
            if ($country !== null) {
                $this->appendText($xml, $party, 'Country', $country);
            } else {
                $party->appendChild($this->nil($xml, 'Country'));
            }
        }

        $this->appendText($xml, $party, 'Name', $this->truncate($this->xmlText((string) ($contact?->company_name ?: 'ND')) ?: 'ND', 255));

        return $party;
    }

    private function buildDocumentTotals(\DOMDocument $xml, Invoice $invoice, bool $includeVatPointDate2): \DOMElement
    {
        $totals = $this->el($xml, 'DocumentTotals');
        $total = $this->el($xml, 'DocumentTotal');
        $totals->appendChild($total);

        $this->appendText($xml, $total, 'TaxableValue', $this->money((float) $invoice->sum_without_vat));

        $taxCode = $this->taxCode($invoice->vatRate);
        if ($taxCode === null) {
            $total->appendChild($this->nil($xml, 'TaxCode'));
        } else {
            $this->appendText($xml, $total, 'TaxCode', $taxCode);
        }

        $rate = $invoice->vatRate?->rate;
        if ($rate === null) {
            $total->appendChild($this->nil($xml, 'TaxPercentage'));
        } else {
            $this->appendText($xml, $total, 'TaxPercentage', number_format((float) $rate, 2, '.', ''));
        }

        $this->appendText($xml, $total, 'Amount', $this->money((float) $invoice->vat));

        if ($includeVatPointDate2) {
            $total->appendChild($this->nil($xml, 'VATPointDate2'));
        }

        return $totals;
    }

    private function invoiceNo(Invoice $invoice): string
    {
        return $this->truncate($this->xmlText($invoice->displayNumber()) ?: 'ND', 70);
    }

    private function invoiceDate(Invoice $invoice): string
    {
        $date = $invoice->invoice_date;
        if ($date === null) {
            throw new RuntimeException('Invoice '.$invoice->displayNumber().' has no date.');
        }

        return $date->toDateString();
    }

    private function registrationAccountDate(Invoice $invoice, Carbon $from, Carbon $until): ?string
    {
        $invoiceDate = $invoice->invoice_date;
        if ($invoiceDate !== null) {
            $day = $invoiceDate->toDateString();
            if ($day >= $from->toDateString() && $day <= $until->toDateString()) {
                return null;
            }
        }

        $registered = $invoice->upload_date?->toDateString();
        if ($registered !== null && $registered >= $from->toDateString() && $registered <= $until->toDateString()) {
            return $registered;
        }

        return $until->toDateString();
    }

    private function partyVat(?Contact $contact): string
    {
        $vat = strtoupper(preg_replace('/\s+/', '', (string) ($contact?->company_vat ?? '')) ?? '');
        if ($vat !== '' && $vat !== 'ND') {
            return $this->truncate($vat, 35);
        }

        $code = preg_replace('/\D+/', '', (string) ($contact?->company_id ?? '')) ?? '';
        if ($code !== '' && $this->countryIso($contact?->company_country) === 'LT') {
            return $this->truncate('LT'.$code, 35);
        }

        return 'ND';
    }

    private function partyRegistration(?Contact $contact): string
    {
        $code = preg_replace('/\D+/', '', (string) ($contact?->company_id ?? '')) ?? '';

        return $code !== '' ? $this->truncate($code, 11) : 'ND';
    }

    private function taxCode(?VatRate $rate): ?string
    {
        $code = strtoupper(trim((string) ($rate?->classificator ?? '')));
        if ($code === '' || ! preg_match('/^PVM[0-9]{1,2}$/', $code)) {
            return null;
        }

        return $code;
    }

    private function taxpayerCode(CompanySetting $company): string
    {
        return preg_replace('/\D+/', '', (string) ($company->company_id ?? '')) ?? '';
    }

    private function countryIso(mixed $name): ?string
    {
        $name = trim((string) $name);
        if ($name === '') {
            return null;
        }

        if (preg_match('/^[A-Z]{2}$/', strtoupper($name))) {
            return strtoupper($name);
        }

        return self::COUNTRY_ISO[$name] ?? null;
    }

    private function normalizeDataType(mixed $value): string
    {
        $value = strtoupper(trim((string) $value));

        return in_array($value, [self::DATA_FULL, self::DATA_PURCHASES, self::DATA_SALES], true)
            ? $value
            : self::DATA_FULL;
    }

    private function normalizePartNumber(mixed $value): string
    {
        $value = strtoupper(preg_replace('/[^A-Z0-9_]/', '', (string) $value) ?? '');

        return $this->truncate($value !== '' ? $value : '1', 20);
    }

    private function requiredDate(mixed $value, string $label): Carbon
    {
        if (blank($value)) {
            throw new RuntimeException($label.' date is required for i.SAF.');
        }

        try {
            return Carbon::parse((string) $value)->startOfDay();
        } catch (\Throwable) {
            throw new RuntimeException($label.' date is invalid.');
        }
    }

    private function money(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function nil(\DOMDocument $xml, string $name): \DOMElement
    {
        $element = $this->el($xml, $name);
        $element->setAttributeNS(self::XSI_NS, 'xsi:nil', 'true');

        return $element;
    }

    private function el(\DOMDocument $xml, string $name, ?string $value = null): \DOMElement
    {
        if ($value === null) {
            return $xml->createElementNS(self::NS, $name);
        }

        $element = $xml->createElementNS(self::NS, $name);
        $element->appendChild($xml->createTextNode($value));

        return $element;
    }

    private function appendText(\DOMDocument $xml, \DOMElement $parent, string $name, string $value): void
    {
        $parent->appendChild($this->el($xml, $name, $value));
    }

    private function truncate(string $value, int $max): string
    {
        return Str::limit($value, $max, '');
    }

    private function xmlText(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? '';
    }
}
