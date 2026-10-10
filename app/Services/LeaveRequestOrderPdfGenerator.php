<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\LeaveRequest;
use Barryvdh\DomPDF\Facade\Pdf;

class LeaveRequestOrderPdfGenerator
{
    public static function generate(LeaveRequest $leave): string
    {
        $leave->loadMissing(['employee', 'leaveRequestType', 'confirmedBy', 'document']);
        $company = CompanySetting::instance();
        $employee = $leave->employee;
        $approver = $leave->confirmedBy;
        $approverName = $approver?->fullName() ?: (string) ($approver?->email ?? '—');

        return Pdf::loadView('pdf.leave-request-isakymas', [
            'leaveRequestId' => $leave->getKey(),
            'orderNumber' => 'AT-'.$leave->getKey().'/'.now()->format('Y'),
            'employeeName' => $employee?->fullName() ?: '—',
            'employeePosition' => $employee?->position,
            'leaveTypeName' => $leave->leaveRequestType?->name ?: 'Atostogos',
            'dateFrom' => $leave->date_from?->format('Y-m-d') ?? '—',
            'dateTo' => $leave->date_to?->format('Y-m-d') ?? '—',
            'comment' => trim((string) ($leave->comment ?? '')),
            'approverName' => $approverName !== '' ? $approverName : '—',
            'companyName' => filled($company->company_name) ? (string) $company->company_name : 'Darbdavys',
            'companyAddress' => $company->company_address,
            'companyId' => $company->company_id,
            'generatedAt' => now()->format('Y-m-d'),
            'prasymasDocumentId' => $leave->document_id,
        ])
            ->setPaper('a4', 'portrait')
            ->output();
    }
}
