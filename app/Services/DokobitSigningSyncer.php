<?php

namespace App\Services;

use App\Enums\DocumentSigningStatus;
use App\Enums\EmployeeContractSigningStatus;
use App\Models\DocumentSigning;
use App\Models\EmployeeContractSigning;
use Illuminate\Support\Facades\Log;
use Throwable;

class DokobitSigningSyncer
{
    /**
     * Poll Dokobit for all pending signings and download completed files.
     *
     * @return array{checked: int, completed: int, failed: int}
     */
    public static function syncPending(): array
    {
        $checked = 0;
        $completed = 0;
        $failed = 0;

        DocumentSigning::query()
            ->where('status', DocumentSigningStatus::Pending->value)
            ->whereNotNull('dokobit_token')
            ->orderBy('id')
            ->each(function (DocumentSigning $signing) use (&$checked, &$completed, &$failed): void {
                $checked++;

                try {
                    $beforeApproved = (bool) $signing->document?->flag_approved;
                    DocumentDokobitSigner::syncStatus($signing->fresh(['document', 'signers']));
                    $signing->refresh();

                    if ($signing->isCompleted() || (! $beforeApproved && (bool) $signing->document?->fresh()?->flag_approved)) {
                        $completed++;
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    Log::warning('Dokobit document signing sync failed', [
                        'document_signing_id' => $signing->getKey(),
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

        EmployeeContractSigning::query()
            ->where('status', EmployeeContractSigningStatus::Pending->value)
            ->whereNotNull('dokobit_token')
            ->orderBy('id')
            ->each(function (EmployeeContractSigning $signing) use (&$checked, &$completed, &$failed): void {
                $checked++;

                try {
                    $beforeApproved = (bool) $signing->document?->flag_approved;
                    EmployeeContractSigner::syncStatus($signing->fresh(['document', 'contract', 'signers']));
                    $signing->refresh();

                    if ($signing->isCompleted() || (! $beforeApproved && (bool) $signing->document?->fresh()?->flag_approved)) {
                        $completed++;
                    }
                } catch (Throwable $exception) {
                    $failed++;
                    Log::warning('Dokobit contract signing sync failed', [
                        'employee_contract_signing_id' => $signing->getKey(),
                        'message' => $exception->getMessage(),
                    ]);
                }
            });

        return [
            'checked' => $checked,
            'completed' => $completed,
            'failed' => $failed,
        ];
    }
}
