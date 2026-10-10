<?php

namespace App\Models;

use App\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Http;

class Currency extends Model
{
    /**
     * @var array<string, string>
     */
    private const COMMON_NAMES = [
        'EUR' => 'Euro',
        'USD' => 'US Dollar',
        'GBP' => 'Pound Sterling',
        'PLN' => 'Polish Zloty',
        'CHF' => 'Swiss Franc',
        'SEK' => 'Swedish Krona',
        'NOK' => 'Norwegian Krone',
        'DKK' => 'Danish Krone',
        'CZK' => 'Czech Koruna',
    ];

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

    public static function defaultCode(): string
    {
        $code = strtoupper(preg_replace('/[^A-Za-z]/', '', Money::currency()) ?? '');

        return strlen($code) === 3 ? $code : 'EUR';
    }

    public static function normalize(?string $code): string
    {
        $normalized = strtoupper(preg_replace('/[^A-Za-z]/', '', (string) $code) ?? '');

        return strlen($normalized) === 3 ? $normalized : self::defaultCode();
    }

    /**
     * @return array<string, string>
     */
    public static function selectOptions(?string $include = null): array
    {
        $default = self::defaultCode();
        $options = [];

        foreach (self::COMMON_NAMES as $code => $name) {
            $options[$code] = self::optionLabel($code, $name);
        }

        foreach (static::query()->orderBy('code')->get(['code', 'name']) as $currency) {
            $code = strtoupper(trim((string) $currency->code));
            if (strlen($code) !== 3) {
                continue;
            }

            $options[$code] = self::optionLabel($code, $currency->name ?: (self::COMMON_NAMES[$code] ?? null));
        }

        if (filled($include)) {
            $include = self::normalize($include);
            $options[$include] ??= self::optionLabel($include, self::COMMON_NAMES[$include] ?? null);
        }

        $rest = $options;
        unset($rest[$default]);
        ksort($rest);

        $options[$default] ??= self::optionLabel($default, self::COMMON_NAMES[$default] ?? null);

        return [$default => $options[$default]] + $rest;
    }

    private static function optionLabel(string $code, ?string $name): string
    {
        $name = trim((string) $name);

        return $name !== '' && strtoupper($name) !== $code
            ? $code.' — '.$name
            : $code;
    }

    /**
     * Foreign units per 1 EUR from the currencies list (EUR → 1).
     */
    public static function rateFor(?string $code): ?float
    {
        $code = self::normalize($code);
        if ($code === 'EUR') {
            return 1.0;
        }

        $rate = static::query()->where('code', $code)->value('rate');
        if ($rate === null) {
            return null;
        }

        $rate = (float) $rate;

        return $rate > 0 ? $rate : null;
    }

    /**
     * Convert an amount in $code to EUR using the currencies list (or an explicit rate).
     * Rate meaning: foreign units per 1 EUR.
     */
    public static function toEur(float $amount, ?string $code, ?float $rate = null): ?float
    {
        $code = self::normalize($code);
        $amount = round($amount, 2);

        if ($code === 'EUR') {
            return $amount;
        }

        $rate ??= self::rateFor($code);
        if ($rate === null || $rate <= 0) {
            return null;
        }

        return round($amount / $rate, 2);
    }

    /**
     * Convert an EUR amount to $code using the currencies list (or an explicit rate).
     */
    public static function fromEur(float $eurAmount, ?string $code, ?float $rate = null): ?float
    {
        $code = self::normalize($code);
        $eurAmount = round($eurAmount, 2);

        if ($code === 'EUR') {
            return $eurAmount;
        }

        $rate ??= self::rateFor($code);
        if ($rate === null || $rate <= 0) {
            return null;
        }

        return round($eurAmount * $rate, 2);
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
