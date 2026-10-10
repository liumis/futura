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
use App\Models\SharepointSetting;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
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

        return DB::transaction(function () use ($contract, $signerRows, $creator, $employee): EmployeeContractSigning {
            $binary = EmployeeContractPdfGenerator::generate($contract);
            $document = self::storeDocument($contract, $binary, $creator);

            $signing = EmployeeContractSigning::query()->create([
                'employee_contract_id' => $contract->getKey(),
                'document_id' => $document->getKey(),
                'name' => 'Employment contract — '.$employee->fullName(),
                'status' => EmployeeContractSigningStatus::Pending,
                'created_by' => $creator->getKey(),
            ]);

            self::createDokobitSigning(
                $signing,
                $binary,
                $signerRows,
                'employee-contract-'.$contract->getKey().'.pdf',
            );

            $contract->update([
                'document_id' => $document->getKey(),
                'status' => EmployeeContractStatus::Ready,
            ]);

            return $signing->fresh(['signers', 'document', 'contract']);
        });
    }

    /**
     * Start Dokobit for an already stored document without replacing the employment contract PDF.
     *
     * @param  list<string>  $signerKeys
     */
    public static function startForExistingDocument(
        EmployeeContract $contract,
        Document $document,
        string $binary,
        array $signerKeys,
        User $creator,
        string $signingName,
        string $filename,
    ): EmployeeContractSigning {
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

        return DB::transaction(function () use ($contract, $document, $binary, $signerRows, $creator, $signingName, $filename): EmployeeContractSigning {
            $signing = EmployeeContractSigning::query()->create([
                'employee_contract_id' => $contract->getKey(),
                'document_id' => $document->getKey(),
                'name' => $signingName,
                'status' => EmployeeContractSigningStatus::Pending,
                'created_by' => $creator->getKey(),
            ]);

            return self::createDokobitSigning($signing, $binary, $signerRows, $filename);
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
                $signedName = $signing->isTermination()
                    ? 'employee-contract-termination-signed-'.$signing->employee_contract_id.'.pdf'
                    : 'employee-contract-signed-'.$signing->employee_contract_id.'.pdf';
                $localPath = 'employee-contracts/'.$signedName;
                Storage::disk('public')->put($localPath, $signedBinary);

                self::persistBinaryToDocument(
                    $document->fresh(['documentType']),
                    $signedBinary,
                    $signedName,
                    $localPath,
                );

                $document->forceFill([
                    'flag_approved' => true,
                    'user_approved_id' => $signing->created_by,
                    'approval_date' => now(),
                    'content_hash' => hash('sha256', $signedBinary),
                    'pdf_hash' => hash('sha256', $signedBinary),
                ])->save();
            }

            $signing->signers()
                ->whereNull('signed_at')
                ->update(['signed_at' => now()]);

            $signing->update([
                'status' => EmployeeContractSigningStatus::Completed,
                'completed_at' => now(),
            ]);

            if ($signing->contract) {
                if ($signing->isTermination()) {
                    EmployeeContractTerminator::applyTermination($signing->contract);
                } else {
                    $signing->contract->update([
                        'status' => EmployeeContractStatus::Signed,
                        'document_id' => $document?->getKey() ?? $signing->contract->document_id,
                    ]);

                    $signing->contract->refresh();
                    $signing->contract->closeOverlappingContracts();
                }
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
        $document = self::makeContractDocument($contract, $binary, $creator);

        DocumentBinaryStore::storeBinary(
            $document->fresh(['documentType']),
            $binary,
            'employee-contract-'.$contract->getKey().'.pdf',
        );

        return $document->fresh(['documentType']);
    }

    /**
     * Attach an already-signed scan/PDF to the contract (no Dokobit).
     */
    public static function attachSignedUpload(EmployeeContract $contract, string $localPath, User $uploader): Document
    {
        if (! Storage::disk('public')->exists($localPath)) {
            throw new RuntimeException('Signed document file was not found.');
        }

        $binary = (string) Storage::disk('public')->get($localPath);
        if ($binary === '') {
            throw new RuntimeException('Signed document file is empty.');
        }

        $contract->loadMissing(['employee', 'document.documentType']);

        $original = basename(str_replace('\\', '/', $localPath));
        $ext = strtolower((string) pathinfo($original, PATHINFO_EXTENSION));
        if ($ext === '') {
            $ext = 'pdf';
        }
        $remoteName = 'employee-contract-'.$contract->getKey().'-signed.'.$ext;

        $document = $contract->document;
        if (! $document instanceof Document) {
            $document = self::makeContractDocument($contract, $binary, $uploader, approved: true);
        }

        self::persistDocumentBinary($document, $binary, $remoteName, $localPath);

        $document->forceFill([
            'flag_approved' => true,
            'user_approved_id' => $uploader->getKey(),
            'approval_date' => now(),
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ])->save();

        if ((int) ($contract->document_id ?? 0) !== (int) $document->getKey()) {
            $contract->update(['document_id' => $document->getKey()]);
        }

        return $document->fresh(['documentType']);
    }

    /**
     * @return list<string>
     */
    public static function normalizeUploadPaths(mixed $state): array
    {
        if ($state === null || $state === '') {
            return [];
        }

        $items = is_array($state) ? $state : [$state];
        $paths = [];

        foreach ($items as $item) {
            if (is_array($item)) {
                continue;
            }

            $path = self::normalizeUploadPath($item);
            if ($path !== null && ! in_array($path, $paths, true)) {
                $paths[] = $path;
            }
        }

        return $paths;
    }

    /**
     * Store one or more local files on the document (SharePoint when configured).
     *
     * @param  list<string>  $localPaths
     */
    public static function persistDocumentFiles(Document $document, array $localPaths, bool $allowWhenLocked = false): void
    {
        $localPaths = array_values(array_unique(array_filter(
            $localPaths,
            fn ($path): bool => filled($path),
        )));

        if ($localPaths === []) {
            return;
        }

        foreach ($localPaths as $path) {
            if (! Storage::disk('public')->exists((string) $path)) {
                throw new RuntimeException('Signed document file was not found.');
            }
        }

        if (SharepointSetting::instance()->isReady()) {
            DocumentBinaryStore::ingestLocalUploads(
                $document->fresh(['documentType']),
                $localPaths,
                $allowWhenLocked,
            );

            return;
        }

        $meta = [];
        if (is_array($document->sharepoint_files)) {
            $meta = array_values(array_filter(
                $document->sharepoint_files,
                fn ($row): bool => is_array($row) && filled($row['path'] ?? null),
            ));
        }

        if ($meta === [] && filled($document->file_path)) {
            $meta[] = self::localFileMeta((string) $document->file_path);
        }

        $existingPaths = array_map(
            fn (array $row): string => (string) $row['path'],
            $meta,
        );

        foreach ($localPaths as $path) {
            if (in_array($path, $existingPaths, true)) {
                continue;
            }

            $meta[] = self::localFileMeta($path);
            $existingPaths[] = $path;
        }

        $document->forceFill([
            'file_path' => $document->file_path ?: $localPaths[0],
            'sharepoint_files' => $meta !== [] ? $meta : null,
        ])->save();
    }

    /**
     * @return array{name: string, path: string, item_id: null, web_url: string}
     */
    protected static function localFileMeta(string $path): array
    {
        return [
            'name' => basename(str_replace('\\', '/', $path)),
            'path' => $path,
            'item_id' => null,
            'web_url' => Storage::disk('public')->url($path),
        ];
    }

    public static function normalizeUploadPath(mixed $state): ?string
    {
        if (is_array($state)) {
            $state = $state[0] ?? null;
        }

        if ($state instanceof TemporaryUploadedFile || $state instanceof UploadedFile) {
            $ext = strtolower((string) $state->getClientOriginalExtension());
            if ($ext === '') {
                $ext = 'pdf';
            }

            $stored = $state->storeAs(
                'employee-contracts',
                (string) Str::ulid().'.'.$ext,
                'public',
            );

            return is_string($stored) && $stored !== '' ? $stored : null;
        }

        if (! is_string($state) || trim($state) === '') {
            return null;
        }

        return trim($state);
    }

    public static function persistBinaryToDocument(
        Document $document,
        string $binary,
        string $fileName,
        ?string $localPath = null,
    ): void {
        self::persistDocumentBinary($document, $binary, $fileName, $localPath);
    }

    /**
     * @param  Collection<int, array{signer_key: string, user_id: int|null, employee_id: int|null, name: string, surname: string, email: string|null}>  $signerRows
     */
    protected static function createDokobitSigning(
        EmployeeContractSigning $signing,
        string $binary,
        Collection $signerRows,
        string $filename,
    ): EmployeeContractSigning {
        $client = DokobitGatewayClient::make();

        $dokobitSigners = [];
        foreach ($signerRows as $row) {
            $dokobitSigners[] = [
                'id' => $row['signer_key'],
                'name' => $row['name'],
                'surname' => $row['surname'] !== '' ? $row['surname'] : '-',
                'signing_purpose' => 'signature',
            ];
        }

        $upload = $client->uploadFileContent($filename, $binary);
        $client->waitUntilUploaded($upload['token']);

        $created = $client->createSigning(
            name: $signing->name,
            signers: $dokobitSigners,
            files: [['token' => $upload['token']]],
            postbackUrl: self::postbackUrl(),
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

        return $signing->fresh(['signers', 'document', 'contract']);
    }

    protected static function makeContractDocument(
        EmployeeContract $contract,
        string $binary,
        User $creator,
        bool $approved = false,
    ): Document {
        $type = DocumentType::query()->firstOrCreate(
            ['name' => 'Employee contract'],
            [],
        );

        $employeeName = $contract->employee?->fullName() ?: ('#'.$contract->employee_id);

        return Document::query()->create([
            'document_date' => $contract->sign_date?->toDateString() ?? now(),
            'name' => 'Employment contract — '.$employeeName,
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

    protected static function persistDocumentBinary(
        Document $document,
        string $binary,
        string $fileName,
        ?string $localPath = null,
    ): void {
        if (SharepointSetting::instance()->isReady()) {
            DocumentBinaryStore::storeBinary(
                $document->fresh(['documentType']),
                $binary,
                $fileName,
            );

            if (filled($localPath)) {
                DocumentBinaryStore::deleteLocalPaths([(string) $localPath]);
            }

            return;
        }

        $document->forceFill([
            'file_path' => $localPath,
        ])->save();
    }
}
