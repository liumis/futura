<?php

namespace App\Services;

use App\Enums\InvoiceLanguage;
use App\Models\VatRate;

class InvoicePdfTranslator
{
    /**
     * @return array<string, string>
     */
    public static function strings(InvoiceLanguage $language): array
    {
        return match ($language) {
            InvoiceLanguage::Lithuanian => [
                'invoice_title' => 'SĄSKAITA FAKTŪRA',
                'invoice_no' => 'Sąskaitos Nr.:',
                'invoice_date' => 'Sąskaitos data:',
                'order_no' => 'Užsakymo Nr.:',
                'tracking' => 'Sekimo Nr.:',
                'from' => 'Tiekėjas',
                'bill_to' => 'Pirkėjas',
                'company_id' => 'Įmonės kodas:',
                'vat' => 'PVM kodas:',
                'collection' => 'Kolekcija',
                'color' => 'Spalva',
                'product_code' => 'Prekės kodas',
                'size_m' => 'Ilgis (m)',
                'qty' => 'Kiekis',
                'unit_price' => 'Vieneto kaina',
                'line_total' => 'Suma',
                'no_line_items' => 'Nėra eilučių',
                'pvm_vat' => 'PVM/VAT',
                'vat_rate_applied' => 'Taikomas PVM tarifas:',
                'thank_you' => 'Dėkojame už bendradarbiavimą.',
                'subtotal' => 'Tarpinė suma',
                'shipping' => 'Pristatymas',
                'amount_excl_vat' => 'Suma be PVM',
                'vat_amount' => 'PVM',
                'total_due' => 'Iš viso mokėti',
                'footer_generated' => 'Sugeneruota',
                'roll_unit' => 'rit.',
                'netto' => 'Netto',
                'brutto' => 'Brutto',
                'packing' => 'pakavimas',
                'plastic' => 'plastikas',
                'carton_i' => 'kartonas I',
                'carton_ii' => 'kartonas II',
            ],
            InvoiceLanguage::English => [
                'invoice_title' => 'INVOICE',
                'invoice_no' => 'Invoice no:',
                'invoice_date' => 'Invoice date:',
                'order_no' => 'Order no:',
                'tracking' => 'Tracking:',
                'from' => 'From',
                'bill_to' => 'Bill to',
                'company_id' => 'Company ID:',
                'vat' => 'VAT:',
                'collection' => 'Collection',
                'color' => 'Color',
                'product_code' => 'Product code',
                'size_m' => 'Size (m)',
                'qty' => 'Qty',
                'unit_price' => 'Unit price',
                'line_total' => 'Line total',
                'no_line_items' => 'No line items',
                'pvm_vat' => 'PVM/VAT',
                'vat_rate_applied' => 'VAT rate applied:',
                'thank_you' => 'Thank you for your business.',
                'subtotal' => 'Subtotal',
                'shipping' => 'Shipping',
                'amount_excl_vat' => 'Amount excl. VAT',
                'vat_amount' => 'VAT',
                'total_due' => 'Total due',
                'footer_generated' => 'Generated',
                'roll_unit' => 'roll.',
                'netto' => 'Netto',
                'brutto' => 'Brutto',
                'packing' => 'packing',
                'plastic' => 'plastic',
                'carton_i' => 'carton I',
                'carton_ii' => 'carton II',
            ],
        };
    }

    /**
     * @return list<string>
     */
    public static function legalLines(?VatRate $vatRate, InvoiceLanguage $language): array
    {
        if ($vatRate === null) {
            return [];
        }

        $classificator = (string) $vatRate->classificator;
        $rate = VatRateResolver::numericRate($vatRate);

        if (in_array($classificator, ['PVM4', 'PVM33', 'PVM16'], true) || $rate <= 0) {
            if ($classificator === 'PVM16') {
                return match ($language) {
                    InvoiceLanguage::Lithuanian => [
                        'PVM įstatymo 96 str. 7 d. (atvirkštinis apmokestinimas).',
                    ],
                    InvoiceLanguage::English => [
                        'Reverse charge — VAT to be accounted for by the recipient.',
                    ],
                };
            }

            if (in_array($classificator, ['PVM15', 'PVM34'], true) && filled($vatRate->description)) {
                return [(string) $vatRate->description];
            }

            return match ($language) {
                InvoiceLanguage::Lithuanian => [
                    'PVM įstatymo 49 str. 1 dalį arba ES Direktyvos 2006/112/EB 138 (1) straipsnis 0% tarifas.',
                ],
                InvoiceLanguage::English => [
                    'Directive 2006/112/EC Article 138 (1) VAT 0% (reverse charge)',
                ],
            };
        }

        if (filled($vatRate->description)) {
            return [(string) $vatRate->description];
        }

        return [];
    }
}
