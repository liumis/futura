<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class Currency extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'name',
        'rate',
        'rate_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rate' => 'decimal:6',
            'rate_date' => 'date',
        ];
    }

    /**
     * Official Lietuvos bankas euro foreign exchange reference rates web service.
     */
    public const LB_FX_RATES_URL = 'https://www.lb.lt/webservices/FxRates/FxRates.asmx/getCurrentFxRates';

    /**
     * Official Lietuvos bankas currency list web service (ISO 4217 names).
     */
    public const LB_CURRENCY_LIST_URL = 'https://www.lb.lt/webservices/FxRates/FxRates.asmx/getCurrencyList';

    /**
     * Import the latest official euro reference rates from Lietuvos bankas.
     * Each rate is the amount of foreign currency per 1 EUR.
     *
     * @return int Number of currencies imported/updated.
     */
    public static function importFromLbBank(): int
    {
        $response = Http::timeout(20)->get(self::LB_FX_RATES_URL, ['tp' => 'EU']);
        $response->throw();

        $xml = simplexml_load_string($response->body());

        if ($xml === false) {
            throw new \RuntimeException('Unable to parse Lietuvos bankas response.');
        }

        $names = self::fetchCurrencyNames();
        $count = 0;

        foreach ($xml->FxRate as $fxRate) {
            $date = (string) $fxRate->Dt;

            // Each FxRate has the base (EUR, amount 1) and the target currency.
            foreach ($fxRate->CcyAmt as $ccyAmt) {
                $code = strtoupper(trim((string) $ccyAmt->Ccy));
                $amount = (float) $ccyAmt->Amt;

                if ($code === 'EUR') {
                    continue;
                }

                self::query()->updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $names[$code] ?? null,
                        'rate' => $amount,
                        'rate_date' => $date ?: null,
                    ],
                );

                $count++;
            }
        }

        return $count;
    }

    /**
     * Fetch the official ISO 4217 currency names from the Lietuvos bankas
     * getCurrencyList web service. Returns a map of code => English name.
     * Fails soft: returns an empty map if the list cannot be retrieved.
     *
     * @return array<string, string>
     */
    public static function fetchCurrencyNames(): array
    {
        try {
            $response = Http::timeout(20)->get(self::LB_CURRENCY_LIST_URL);
            $response->throw();

            $xml = simplexml_load_string($response->body());

            if ($xml === false) {
                return [];
            }

            $names = [];

            foreach ($xml->CcyNtry as $entry) {
                $code = strtoupper(trim((string) $entry->Ccy));

                if ($code === '') {
                    continue;
                }

                foreach ($entry->CcyNm as $ccyNm) {
                    if ((string) $ccyNm->attributes()->lang === 'EN') {
                        $names[$code] = trim((string) $ccyNm);
                        break;
                    }
                }
            }

            return $names;
        } catch (\Throwable) {
            return [];
        }
    }
}
