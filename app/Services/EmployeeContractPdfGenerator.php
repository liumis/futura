<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeeContract;
use App\Support\Money;
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeeContractPdfGenerator
{
    public static function generate(EmployeeContract $contract): string
    {
        $contract->loadMissing('employee');

        ActivityLogger::logReportDownloaded('Employee contract PDF', 'pdf', $contract, [
            'employee' => $contract->employee?->fullName(),
            'contract_id' => $contract->getKey(),
        ]);

        return Pdf::loadView('pdf.employee-contract', [
            'contract' => $contract,
            'employee' => $contract->employee,
            'company' => CompanySetting::instance(),
            'baseSalary' => Money::format((float) $contract->base_salary),
            'defaultBonus' => $contract->default_bonus !== null
                ? Money::format((float) $contract->default_bonus)
                : null,
        ])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
