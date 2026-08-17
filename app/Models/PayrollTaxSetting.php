<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PayrollTaxSetting extends Model
{
    protected $fillable = [
        'year',
        'mma',
        'npd_max',
        'npd_coefficient',
        'npd_disability_0_25',
        'npd_disability_30_55',
        'employee_sodra_rate',
        'gpm_rate',
        'employer_sodra_permanent_rate',
        'employer_sodra_fixed_term_rate',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'mma' => 'decimal:2',
            'npd_max' => 'decimal:2',
            'npd_coefficient' => 'decimal:4',
            'npd_disability_0_25' => 'decimal:2',
            'npd_disability_30_55' => 'decimal:2',
            'employee_sodra_rate' => 'decimal:4',
            'gpm_rate' => 'decimal:4',
            'employer_sodra_permanent_rate' => 'decimal:4',
            'employer_sodra_fixed_term_rate' => 'decimal:4',
        ];
    }

    public static function forYear(int $year): self
    {
        return static::query()->firstOrCreate(
            ['year' => $year],
            self::defaultsForYear($year),
        );
    }

    /**
     * @return array<string, float|int>
     */
    public static function defaultsForYear(int $year): array
    {
        // 2026 VMI / Sodra baseline values; reuse for nearby years until updated.
        return [
            'year' => $year,
            'mma' => 1153.00,
            'npd_max' => 747.00,
            'npd_coefficient' => 0.49,
            'npd_disability_0_25' => 1127.00,
            'npd_disability_30_55' => 1057.00,
            'employee_sodra_rate' => 0.1950,
            'gpm_rate' => 0.2000,
            'employer_sodra_permanent_rate' => 0.0177,
            'employer_sodra_fixed_term_rate' => 0.0249,
        ];
    }
}
