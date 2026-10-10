<?php

namespace App\Services;

use App\Enums\EmployeeContractSigningStatus;
use App\Enums\EmployeeContractStatus;
use App\Enums\EmployeeStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeContractSigning;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class EmployeeContractTerminator
{
    /**
     * @param  list<string>  $signerKeys
     */
    public static function startSigning(
        EmployeeContract $contract,
        string $terminationDate,
        string $reason,
        array $signerKeys,
        User $creator,
    ): EmployeeContractSigning {
        self::assertCanEnd($contract);

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Enter a termination reason.');
        }

        $contract->update([
            'termination_date' => $terminationDate,
            'termination_reason' => $reason,
        ]);
        $contract->refresh()->loadMissing(['employee', 'terminationDocument.documentType']);

        $binary = EmployeeContractTerminationPdfGenerator::generate($contract);
        $document = self::storeTerminationDocument($contract, $binary, $creator, approved: false);
        $employeeName = $contract->employee?->fullName() ?: ('#'.$contract->employee_id);

        $signing = EmployeeContractSigner::startForExistingDocument(
            $contract,
            $document,
            $binary,
            $signerKeys,
            $creator,
            'Employment contract termination — '.$employeeName,
            'employee-contract-termination-'.$contract->getKey().'.pdf',
        );

        if ((int) ($contract->termination_document_id ?? 0) !== (int) $document->getKey()) {
            $contract->update(['termination_document_id' => $document->getKey()]);
        }

        return $signing;
    }

    public static function attachSignedUpload(
        EmployeeContract $contract,
        string $terminationDate,
        string $reason,
        string $localPath,
        User $uploader,
    ): Document {
        return self::attachSignedUploads($contract, $terminationDate, $reason, [$localPath], $uploader);
    }

    /**
     * @param  list<string>  $localPaths
     */
    public static function attachSignedUploads(
        EmployeeContract $contract,
        string $terminationDate,
        string $reason,
        array $localPaths,
        User $uploader,
    ): Document {
        self::assertCanEnd($contract);

        $reason = trim($reason);
        if ($reason === '') {
            throw new RuntimeException('Enter a termination reason.');
        }

        $localPaths = array_values(array_unique(array_filter(
            $localPaths,
            fn ($path): bool => filled($path),
        )));

        if ($localPaths === []) {
            throw new RuntimeException('Signed document file was not found.');
        }

        $primary = $localPaths[0];
        if (! Storage::disk('public')->exists($primary)) {
            throw new RuntimeException('Signed document file was not found.');
        }

        $binary = (string) Storage::disk('public')->get($primary);
        if ($binary === '') {
            throw new RuntimeException('Signed document file is empty.');
        }

        $contract->update([
            'termination_date' => $terminationDate,
            'termination_reason' => $reason,
        ]);
        $contract->refresh()->loadMissing(['employee', 'terminationDocument.documentType']);

        $document = $contract->terminationDocument;
        if (! $document instanceof Document || $document->isApproved()) {
            $document = self::makeTerminationDocument($contract, $binary, $uploader, approved: false);
            $contract->update(['termination_document_id' => $document->getKey()]);
            $contract->setRelation('terminationDocument', $document);
        }

        EmployeeContractSigner::persistDocumentFiles($document, $localPaths, allowWhenLocked: true);

        $document->forceFill([
            'flag_approved' => true,
            'user_approved_id' => $uploader->getKey(),
            'approval_date' => now(),
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ])->save();

        EmployeeContractSigning::query()
            ->where('employee_contract_id', $contract->getKey())
            ->where('document_id', $document->getKey())
            ->where('status', EmployeeContractSigningStatus::Pending->value)
            ->update([
                'status' => EmployeeContractSigningStatus::Completed->value,
                'completed_at' => now(),
            ]);

        self::applyTermination($contract->fresh(['employee']));

        return $document->fresh(['documentType']);
    }

    /**
     * Attach extra scans/PDFs to an existing termination document.
     *
     * @param  list<string>  $localPaths
     */
    public static function attachAdditionalFiles(
        EmployeeContract $contract,
        array $localPaths,
        User $uploader,
    ): Document {
        $document = $contract->terminationDocument;
        if (! $document instanceof Document) {
            throw new RuntimeException('No termination document to attach files to.');
        }

        $localPaths = array_values(array_unique(array_filter(
            $localPaths,
            fn ($path): bool => filled($path),
        )));

        if ($localPaths === []) {
            throw new RuntimeException('Choose at least one file to attach.');
        }

        EmployeeContractSigner::persistDocumentFiles($document, $localPaths, allowWhenLocked: true);

        return $document->fresh(['documentType']);
    }

    public static function applyTermination(EmployeeContract $contract): void
    {
        $contract->refresh()->loadMissing('employee');

        $validTo = $contract->termination_date?->toDateString() ?? $contract->valid_to?->toDateString();

        $contract->update([
            'status' => EmployeeContractStatus::Inactive,
            'valid_to' => $validTo,
        ]);

        $employee = $contract->employee;
        if (! $employee instanceof Employee) {
            return;
        }

        $hasOtherSigned = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->whereKeyNot($contract->getKey())
            ->where('status', EmployeeContractStatus::Signed)
            ->exists();

        if ($hasOtherSigned) {
            return;
        }

        $employee->update([
            'status' => EmployeeStatus::Inactive,
            'contract_end_date' => $contract->termination_date?->toDateString() ?? $employee->contract_end_date,
        ]);
    }

    protected static function assertCanEnd(EmployeeContract $contract): void
    {
        if (! $contract->canEnd()) {
            throw new RuntimeException('This contract cannot be ended.');
        }
    }

    protected static function storeTerminationDocument(
        EmployeeContract $contract,
        string $binary,
        User $actor,
        bool $approved,
        ?string $localPath = null,
        ?string $fileName = null,
    ): Document {
        $document = $contract->terminationDocument;
        if (! $document instanceof Document || $document->isApproved()) {
            $document = self::makeTerminationDocument($contract, $binary, $actor, $approved);
            $contract->update(['termination_document_id' => $document->getKey()]);
            $contract->setRelation('terminationDocument', $document);
        }

        $fileName ??= 'employee-contract-termination-'.$contract->getKey().'.pdf';

        if ($localPath === null) {
            $localPath = 'employee-contracts/termination-'.$contract->getKey().'.pdf';
            Storage::disk('public')->put($localPath, $binary);
        }

        EmployeeContractSigner::persistBinaryToDocument($document, $binary, $fileName, $localPath);

        return $document->fresh(['documentType']);
    }

    protected static function makeTerminationDocument(
        EmployeeContract $contract,
        string $binary,
        User $creator,
        bool $approved = false,
    ): Document {
        $type = DocumentType::query()->firstOrCreate(
            ['name' => 'Employee contract termination'],
            [],
        );

        $employeeName = $contract->employee?->fullName() ?: ('#'.$contract->employee_id);

        return Document::query()->create([
            'document_date' => $contract->termination_date?->toDateString() ?? now(),
            'name' => 'Employment contract termination — '.$employeeName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $creator->getKey(),
            'flag_approved' => $approved,
            'user_approved_id' => $approved ? $creator->getKey() : null,
            'approval_date' => $approved ? now() : null,
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);
    }
}
