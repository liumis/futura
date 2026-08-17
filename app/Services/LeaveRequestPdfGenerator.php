<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\LeaveRequest;
use Barryvdh\DomPDF\Facade\Pdf;

class LeaveRequestPdfGenerator
{
    public static function generate(LeaveRequest $leave): string
    {
        $leave->loadMissing(['employee', 'leaveRequestType']);
        $company = CompanySetting::instance();
        $employee = $leave->employee;

        return Pdf::loadView('pdf.leave-request-prasymas', [
            'leaveRequestId' => $leave->getKey(),
            'employeeName' => $employee?->fullName() ?: '—',
            'employeePosition' => $employee?->position,
            'leaveTypeName' => $leave->leaveRequestType?->name ?: 'Atostogos',
            'dateFrom' => $leave->date_from?->format('Y-m-d') ?? '—',
            'dateTo' => $leave->date_to?->format('Y-m-d') ?? '—',
            'comment' => trim((string) ($leave->comment ?? '')),
            'companyName' => filled($company->company_name) ? (string) $company->company_name : 'Darbdavys',
            'companyAddress' => $company->company_address,
            'companyId' => $company->company_id,
            'generatedAt' => now()->format('Y-m-d'),
        ])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
