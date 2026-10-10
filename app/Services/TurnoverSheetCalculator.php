<?php

namespace App\Services;

/**
 * Lithuanian general-ledger turnover sheet (apyvartos žiniaraštis / didžioji apskaitos knyga):
 * opening and closing balances are shown on one side only (debit or credit).
 */
final class TurnoverSheetCalculator
{
    /**
     * @return array{debit: float, credit: float}
     */
    public static function splitBalance(float $debit, float $credit): array
    {
        $net = round($debit - $credit, 2);

        if ($net >= 0.005) {
            return ['debit' => $net, 'credit' => 0.0];
        }

        if ($net <= -0.005) {
            return ['debit' => 0.0, 'credit' => round(abs($net), 2)];
        }

        return ['debit' => 0.0, 'credit' => 0.0];
    }

    /**
     * @return array{
     *     opening_debit: float,
     *     opening_credit: float,
     *     turnover_debit: float,
     *     turnover_credit: float,
     *     closing_debit: float,
     *     closing_credit: float
     * }
     */
    public static function forAccount(
        float $openingDebitSum,
        float $openingCreditSum,
        float $turnoverDebit,
        float $turnoverCredit,
    ): array {
        $turnoverDebit = round($turnoverDebit, 2);
        $turnoverCredit = round($turnoverCredit, 2);
        $opening = self::splitBalance($openingDebitSum, $openingCreditSum);
        $closing = self::splitBalance(
            $opening['debit'] + $turnoverDebit,
            $opening['credit'] + $turnoverCredit,
        );

        return [
            'opening_debit' => $opening['debit'],
            'opening_credit' => $opening['credit'],
            'turnover_debit' => $turnoverDebit,
            'turnover_credit' => $turnoverCredit,
            'closing_debit' => $closing['debit'],
            'closing_credit' => $closing['credit'],
        ];
    }
}
