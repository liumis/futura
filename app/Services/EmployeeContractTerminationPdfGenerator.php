<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\EmployeeContract;
use Barryvdh\DomPDF\Facade\Pdf;

class EmployeeContractTerminationPdfGenerator
{
    public static function generate(EmployeeContract $contract): string
    {
        $contract->loadMissing('employee');

        ActivityLogger::logReportDownloaded('Employee contract termination PDF', 'pdf', $contract, [
            'employee' => $contract->employee?->fullName(),
            'contract_id' => $contract->getKey(),
        ]);

        return Pdf::loadView('pdf.employee-contract-termination', [
            'contract' => $contract,
            'employee' => $contract->employee,
            'company' => CompanySetting::instance(),
        ])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
