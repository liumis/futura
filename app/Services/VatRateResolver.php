<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Models\VatRate;

class VatRateResolver
{
    public static function default(): ?VatRate
    {
        return VatRate::query()
            ->where('classificator', 'PVM1')
            ->first()
            ?? VatRate::query()->whereNotNull('rate')->orderBy('id')->first();
    }

    public static function forUser(?User $user): ?VatRate
    {
        if ($user !== null) {
            $user->loadMissing('vatRate');

            if ($user->vatRate !== null) {
                return $user->vatRate;
            }
        }

        return self::default();
    }

    public static function forInvoice(Invoice $invoice, ?User $user = null): ?VatRate
    {
        $invoice->loadMissing('vatRate');

        if ($invoice->vatRate !== null) {
            return $invoice->vatRate;
        }

        return self::forUser($user);
    }

    public static function numericRate(?VatRate $vatRate): float
    {
        if ($vatRate === null || $vatRate->rate === null) {
            return 0.0;
        }

        return (float) $vatRate->rate;
    }

    /**
     * @return array{0: string, 1: string, 2: string}
     */
    public static function calculateAmounts(float $sumIncVat, ?VatRate $vatRate): array
    {
        $sumIncVat = round($sumIncVat, 2);
        $rate = self::numericRate($vatRate);

        if ($rate > 0) {
            $sumWithoutVat = round($sumIncVat / (1 + ($rate / 100)), 2);
            $vat = round($sumIncVat - $sumWithoutVat, 2);
        } else {
            $sumWithoutVat = $sumIncVat;
            $vat = 0.0;
        }

        return [
            number_format($sumWithoutVat, 2, '.', ''),
            number_format($vat, 2, '.', ''),
            number_format($sumIncVat, 2, '.', ''),
        ];
    }

    public static function optionLabel(VatRate $vatRate): string
    {
        $label = (string) $vatRate->classificator;

        if ($vatRate->rate !== null) {
            return sprintf('%s — %s%%', $label, number_format((float) $vatRate->rate, 2));
        }

        if (filled($vatRate->description)) {
            return sprintf('%s — %s', $label, $vatRate->description);
        }

        return $label;
    }

    public static function displayLabel(?VatRate $vatRate): ?string
    {
        if ($vatRate === null) {
            return null;
        }

        return self::optionLabel($vatRate);
    }

    /**
     * @return list<string>
     */
    public static function legalLines(?VatRate $vatRate): array
    {
        if ($vatRate === null) {
            return [];
        }

        $classificator = (string) $vatRate->classificator;
        $rate = self::numericRate($vatRate);

        if (in_array($classificator, ['PVM4', 'PVM33', 'PVM16'], true) || $rate <= 0) {
            if ($classificator === 'PVM16') {
                return [
                    'PVM įstatymo 96 str. 7 d. (atvirkštinis apmokestinimas).',
                    'Reverse charge — VAT to be accounted for by the recipient.',
                ];
            }

            if (in_array($classificator, ['PVM15', 'PVM34'], true) && filled($vatRate->description)) {
                return [(string) $vatRate->description];
            }

            return [
                'PVM įstatymo 49 str. 1 dalį arba ES Direktyvos 2006/112/EB 138 (1) straipsnis 0% tarifas.',
                'Directive 2006/112/EC Article 138 (1) VAT 0% (reverse charge)',
            ];
        }

        if (filled($vatRate->description)) {
            return [(string) $vatRate->description];
        }

        return [];
    }
}
