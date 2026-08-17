<?php

namespace App\Services;

use App\Enums\EmployeeContractSigningStatus;
use App\Enums\EmployeeContractStatus;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeContractSigning;
use App\Models\EmployeeContractSigningSigner;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class EmployeeContractSigner
{
    /**
     * @param  list<string>  $signerKeys  Keys like "employee:1" or "user:5"
     */
    public static function start(EmployeeContract $contract, array $signerKeys, User $creator): EmployeeContractSigning
    {
        if ($contract->status === EmployeeContractStatus::Inactive) {
            throw new RuntimeException('Inactive contracts cannot be signed.');
        }

        $pending = EmployeeContractSigning::query()
            ->where('employee_contract_id', $contract->getKey())
            ->where('status', EmployeeContractSigningStatus::Pending->value)
            ->exists();

        if ($pending) {
            throw new RuntimeException('This contract already has a signing in progress. Finish or cancel it first.');
        }

        $keys = collect($signerKeys)
            ->map(fn ($key): string => (string) $key)
            ->filter(fn (string $key): bool => $key !== '')
            ->unique()
            ->values();

        if ($keys->isEmpty()) {
            throw new RuntimeException('Select at least one person for signing.');
        }

        $contract->loadMissing('employee');
        $employee = $contract->employee;

        if (! $employee instanceof Employee) {
            throw new RuntimeException('Contract has no employee.');
        }

        $signerRows = self::resolveSignerRows($keys, $employee);

        if ($signerRows->isEmpty()) {
            throw new RuntimeException('No valid signers selected.');
        }

        $client = DokobitGatewayClient::make();

        return DB::transaction(function () use ($contract, $signerRows, $creator, $client, $employee): EmployeeContractSigning {
            $binary = EmployeeContractPdfGenerator::generate($contract);
            $document = self::storeDocument($contract, $binary, $creator);

            $signing = EmployeeContractSigning::query()->create([
                'employee_contract_id' => $contract->getKey(),
                'document_id' => $document->getKey(),
                'name' => 'Employment contract — '.$employee->fullName(),
                'status' => EmployeeContractSigningStatus::Pending,
                'created_by' => $creator->getKey(),
            ]);

            $dokobitSigners = [];
            foreach ($signerRows as $index => $row) {
                $dokobitSigners[] = [
                    'id' => $row['signer_key'],
                    'name' => $row['name'],
                    'surname' => $row['surname'] !== '' ? $row['surname'] : '-',
                    'signing_purpose' => 'signature',
                ];
            }

            $filename = 'employee-contract-'.$contract->getKey().'.pdf';
            $upload = $client->uploadFileContent($filename, $binary);
            $client->waitUntilUploaded($upload['token']);

            $postbackUrl = self::postbackUrl();

            $created = $client->createSigning(
                name: $signing->name,
                signers: $dokobitSigners,
                files: [['token' => $upload['token']]],
                postbackUrl: $postbackUrl,
            );

            $signing->update([
                'dokobit_token' => $created['token'],
                'dokobit_file_token' => $upload['token'],
            ]);

            foreach ($signerRows as $row) {
                $accessToken = $created['signers'][$row['signer_key']] ?? null;

                EmployeeContractSigningSigner::query()->create([
                    'employee_contract_signing_id' => $signing->getKey(),
                    'signer_key' => $row['signer_key'],
                    'user_id' => $row['user_id'],
                    'employee_id' => $row['employee_id'],
                    'name' => $row['name'],
                    'surname' => $row['surname'],
                    'email' => $row['email'],
                    'dokobit_access_token' => $accessToken,
                    'signing_url' => filled($accessToken)
                        ? $client->signingUrl($created['token'], (string) $accessToken)
                        : null,
                ]);
            }

            $contract->update([
                'document_id' => $document->getKey(),
                'status' => EmployeeContractStatus::Ready,
            ]);

            return $signing->fresh(['signers', 'document', 'contract']);
        });
    }

    public static function openSigningUrl(Document $document, ?User $user = null): ?string
    {
        $signing = $document->contractSigning;
        if ($signing === null || ! $signing->isPending()) {
            return null;
        }

        $signing->loadMissing('signers');

        $signer = $signing->signerForUser($user);
        if ($signer && filled($signer->signing_url) && ! $signer->hasSigned()) {
            return $signer->signing_url;
        }

        return $signing->firstPendingSigningUrl();
    }

    /**
     * Sync Dokobit status and finalize when completed.
     */
    public static function syncStatus(EmployeeContractSigning $signing): EmployeeContractSigning
    {
        if (! $signing->isPending() || blank($signing->dokobit_token)) {
            return $signing;
        }

        $client = DokobitGatewayClient::make();
        $status = $client->signingStatus((string) $signing->dokobit_token);

        $dokobitStatus = (string) ($status['status'] ?? '');

        if (isset($status['signers']) && is_array($status['signers'])) {
            foreach ($status['signers'] as $signerKey => $signerStatus) {
                $signed = is_array($signerStatus)
                    ? (($signerStatus['status'] ?? null) === 'signed' || filled($signerStatus['signed_at'] ?? null))
                    : ((string) $signerStatus === 'signed');

                if (! $signed) {
                    continue;
                }

                EmployeeContractSigningSigner::query()
                    ->where('employee_contract_signing_id', $signing->getKey())
                    ->where('signer_key', (string) $signerKey)
                    ->whereNull('signed_at')
                    ->update(['signed_at' => now()]);
            }
        }

        if ($dokobitStatus === 'completed') {
            $fileUrl = (string) ($status['file'] ?? '');
            if ($fileUrl === '') {
                throw new RuntimeException('Dokobit signing is completed but no file URL was returned.');
            }

            self::complete($signing, $client->downloadSignedFile($fileUrl));
        }

        return $signing->fresh(['signers', 'document', 'contract']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function handlePostback(array $payload): void
    {
        $action = (string) ($payload['action'] ?? '');
        $token = (string) ($payload['token'] ?? $payload['signing_token'] ?? '');

        if ($token === '') {
            Log::warning('Dokobit postback missing token', ['payload' => $payload]);

            return;
        }

        $signing = EmployeeContractSigning::query()
            ->where('dokobit_token', $token)
            ->first();

        if ($signing === null) {
            Log::warning('Dokobit postback for unknown signing', ['token' => $token]);

            return;
        }

        if ($action === 'signer_signed') {
            $signerId = (string) ($payload['signer_id'] ?? $payload['signer'] ?? '');
            if ($signerId !== '') {
                EmployeeContractSigningSigner::query()
                    ->where('employee_contract_signing_id', $signing->getKey())
                    ->where('signer_key', $signerId)
                    ->whereNull('signed_at')
                    ->update(['signed_at' => now()]);
            }

            return;
        }

        if ($action === 'signing_completed') {
            $fileUrl = (string) ($payload['file'] ?? '');
            if ($fileUrl === '') {
                self::syncStatus($signing);

                return;
            }

            $binary = DokobitGatewayClient::make()->downloadSignedFile($fileUrl);
            self::complete($signing, $binary);
        }
    }

    public static function complete(EmployeeContractSigning $signing, string $signedBinary): void
    {
        if ($signing->isCompleted()) {
            return;
        }

        DB::transaction(function () use ($signing, $signedBinary): void {
            $signing->loadMissing(['document', 'contract', 'signers']);

            $document = $signing->document;
            if ($document instanceof Document) {
                DocumentBinaryStore::storeBinary(
                    $document->fresh(['documentType']),
                    $signedBinary,
                    'employee-contract-signed-'.$signing->employee_contract_id.'.pdf',
                );

                $document->update([
                    'file_path' => null,
                    'approved_file_path' => null,
                    'flag_approved' => true,
                    'user_approved_id' => $signing->created_by,
                    'approval_date' => now(),
                    'content_hash' => hash('sha256', $signedBinary),
                    'pdf_hash' => hash('sha256', $signedBinary),
                ]);
            }

            $signing->signers()
                ->whereNull('signed_at')
                ->update(['signed_at' => now()]);

            $signing->update([
                'status' => EmployeeContractSigningStatus::Completed,
                'completed_at' => now(),
            ]);

            if ($signing->contract) {
                $signing->contract->update([
                    'status' => EmployeeContractStatus::Signed,
                    'document_id' => $document?->getKey() ?? $signing->contract->document_id,
                ]);
            }
        });
    }

    public static function postbackUrl(): ?string
    {
        $url = url('/dokobit/postback');

        // Dokobit cannot reach localhost — skip postback in local environments.
        if (str_contains($url, '127.0.0.1') || str_contains($url, 'localhost')) {
            return null;
        }

        return $url;
    }

    /**
     * Options for the Sign modal: employee first, then users.
     *
     * @return array{options: array<string, string>, defaults: list<string>}
     */
    public static function signerSelectOptions(EmployeeContract $contract): array
    {
        $contract->loadMissing('employee');
        $employee = $contract->employee;

        $options = [];
        $defaults = [];

        if ($employee instanceof Employee) {
            $key = 'employee:'.$employee->getKey();
            $options[$key] = $employee->fullName().' (employee)';
            $defaults[] = $key;
        }

        $users = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'customer'))
            ->orderBy('name')
            ->orderBy('surname')
            ->get();

        foreach ($users as $user) {
            $key = 'user:'.$user->getKey();
            $name = trim($user->fullName());
            $label = $name !== ''
                ? (filled($user->email) ? $name.' ('.$user->email.')' : $name)
                : (string) ($user->email ?? 'User #'.$user->getKey());
            $options[$key] = $label;
        }

        return [
            'options' => $options,
            'defaults' => $defaults,
        ];
    }

    /**
     * @param  Collection<int, string>  $keys
     * @return Collection<int, array{signer_key: string, user_id: int|null, employee_id: int|null, name: string, surname: string, email: string|null}>
     */
    protected static function resolveSignerRows(Collection $keys, Employee $employee): Collection
    {
        $rows = collect();

        foreach ($keys as $key) {
            if (str_starts_with($key, 'employee:')) {
                $employeeId = (int) substr($key, strlen('employee:'));
                if ($employeeId !== (int) $employee->getKey()) {
                    $other = Employee::query()->find($employeeId);
                    if (! $other) {
                        continue;
                    }
                    $target = $other;
                } else {
                    $target = $employee;
                }

                $parts = preg_split('/\s+/', trim($target->fullName()), 2) ?: [];
                $rows->push([
                    'signer_key' => 'employee:'.$target->getKey(),
                    'user_id' => null,
                    'employee_id' => (int) $target->getKey(),
                    'name' => (string) ($parts[0] ?? $target->name ?? 'Employee'),
                    'surname' => (string) ($parts[1] ?? $target->surname ?? ''),
                    'email' => $target->email,
                ]);

                continue;
            }

            if (str_starts_with($key, 'user:')) {
                $userId = (int) substr($key, strlen('user:'));
                $user = User::query()->find($userId);
                if (! $user) {
                    continue;
                }

                $rows->push([
                    'signer_key' => 'user:'.$user->getKey(),
                    'user_id' => (int) $user->getKey(),
                    'employee_id' => null,
                    'name' => filled($user->name) ? (string) $user->name : 'User',
                    'surname' => (string) ($user->surname ?? ''),
                    'email' => $user->email,
                ]);
            }
        }

        return $rows->unique('signer_key')->values();
    }

    protected static function storeDocument(EmployeeContract $contract, string $binary, User $creator): Document
    {
        $type = DocumentType::query()->firstOrCreate(
            ['name' => 'Employee contract'],
            [],
        );

        $employeeName = $contract->employee?->fullName() ?: ('#'.$contract->employee_id);

        $document = Document::query()->create([
            'document_date' => now(),
            'name' => 'Employment contract — '.$employeeName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $creator->getKey(),
            'flag_approved' => false,
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        DocumentBinaryStore::storeBinary(
            $document->fresh(['documentType']),
            $binary,
            'employee-contract-'.$contract->getKey().'.pdf',
        );

        return $document->fresh(['documentType']);
    }
}
