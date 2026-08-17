<?php

namespace App\Services;

class LithuanianDividendCalculator
{
    /**
     * @return array{gross: float, gpm: float, net: float}
     */
    public function calculate(float $gross): array
    {
        $gross = round(max(0, $gross), 2);
        $gpm = round($gross * 0.20, 2);
        $net = round(max(0, $gross - $gpm), 2);

        return [
            'gross' => $gross,
            'gpm' => $gpm,
            'net' => $net,
        ];
    }
}

