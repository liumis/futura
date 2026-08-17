<?php

namespace App\Services;

use App\Enums\DocumentSigningStatus;
use App\Models\Document;
use App\Models\DocumentSigning;
use App\Models\DocumentSigningSigner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class DocumentDokobitSigner
{
    /**
     * @param  list<int|string>  $signerUserIds
     * @param  list<array{name: string, surname: string, email: string}>  $externalInvitees
     */
    public static function start(
        Document $document,
        array $signerUserIds,
        User $creator,
        array $externalInvitees = [],
    ): DocumentSigning {
        if ($document->isApproved()) {
            throw new RuntimeException('Approved documents cannot be signed again.');
        }

        if ($document->awaitsDokobitSignature()) {
            throw new RuntimeException('This document already has a Dokobit signing in progress.');
        }

        if (! DocumentBinaryStore::hasFile($document)) {
            throw new RuntimeException('Document file is missing. Upload a PDF before signing.');
        }

        $binary = DocumentBinaryStore::getBinary($document);
        $fileName = DocumentBinaryStore::downloadFileName($document);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== 'pdf' && ! str_starts_with($binary, '%PDF')) {
            throw new RuntimeException('Dokobit signing currently supports PDF files only.');
        }

        if ($binary === '') {
            throw new RuntimeException('Could not read the document file.');
        }

        $userIds = collect($signerUserIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $users = $userIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $userIds)
                ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'customer'))
                ->get();

        $externals = collect($externalInvitees)
            ->filter(fn (array $row): bool => filled($row['email'] ?? null))
            ->values();

        if ($users->isEmpty() && $externals->isEmpty()) {
            throw new RuntimeException('Select at least one internal signer or external invitee.');
        }

        $client = DokobitGatewayClient::make();

        return DB::transaction(function () use ($document, $users, $externals, $creator, $client, $binary, $fileName): DocumentSigning {
            $signing = DocumentSigning::query()->create([
                'document_id' => $document->getKey(),
                'name' => (string) $document->name,
                'status' => DocumentSigningStatus::Pending,
                'created_by' => $creator->getKey(),
            ]);

            $dokobitSigners = [];
            $signerRows = [];

            foreach ($users as $user) {
                $key = 'user:'.$user->getKey();
                $dokobitSigners[] = [
                    'id' => $key,
                    'name' => filled($user->name) ? (string) $user->name : 'User',
                    'surname' => filled($user->surname) ? (string) $user->surname : '-',
                    'signing_purpose' => 'signature',
                ];
                $signerRows[] = [
                    'signer_key' => $key,
                    'user_id' => (int) $user->getKey(),
                    'name' => filled($user->name) ? (string) $user->name : 'User',
                    'surname' => (string) ($user->surname ?? ''),
                    'email' => $user->email,
                    'is_external' => false,
                ];
            }

            foreach ($externals as $index => $external) {
                $key = 'external:'.Str::lower(Str::slug((string) $external['email'], '-')).'-'.$index;
                $dokobitSigners[] = [
                    'id' => $key,
                    'name' => (string) $external['name'],
                    'surname' => (string) $external['surname'],
                    'signing_purpose' => 'signature',
                ];
                $signerRows[] = [
                    'signer_key' => $key,
                    'user_id' => null,
                    'name' => (string) $external['name'],
                    'surname' => (string) $external['surname'],
                    'email' => (string) $external['email'],
                    'is_external' => true,
                ];
            }

            $filename = $fileName !== '' ? $fileName : ('document-'.$document->getKey().'.pdf');
            $upload = $client->uploadFileContent($filename, $binary);
            $client->waitUntilUploaded($upload['token']);

            $created = $client->createSigning(
                name: $signing->name,
                signers: $dokobitSigners,
                files: [['token' => $upload['token']]],
                postbackUrl: EmployeeContractSigner::postbackUrl(),
            );

            $signing->update([
                'dokobit_token' => $created['token'],
                'dokobit_file_token' => $upload['token'],
            ]);

            foreach ($signerRows as $row) {
                $accessToken = $created['signers'][$row['signer_key']] ?? null;

                DocumentSigningSigner::query()->create([
                    'document_signing_id' => $signing->getKey(),
                    'signer_key' => $row['signer_key'],
                    'user_id' => $row['user_id'],
                    'name' => $row['name'],
                    'surname' => $row['surname'],
                    'email' => $row['email'],
                    'is_external' => $row['is_external'],
                    'dokobit_access_token' => $accessToken,
                    'signing_url' => filled($accessToken)
                        ? $client->signingUrl($created['token'], (string) $accessToken)
                        : null,
                ]);
            }

            return $signing->fresh(['signers', 'document']);
        });
    }

    public static function openSigningUrl(Document $document, ?User $user = null): ?string
    {
        $signing = $document->documentSigning;
        if ($signing === null || ! $signing->isPending()) {
            return null;
        }

        $signing->loadMissing('signers');

        $signer = $signing->signerForUser($user);
        if ($signer === null || $signer->is_external || $signer->hasSigned() || blank($signer->signing_url)) {
            return null;
        }

        // Only the signed-in internal user may open their own Dokobit link in-app.
        // External invitees sign via the email link on Dokobit directly.
        return $signer->signing_url;
    }

    public static function syncStatus(DocumentSigning $signing): DocumentSigning
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

                DocumentSigningSigner::query()
                    ->where('document_signing_id', $signing->getKey())
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

        return $signing->fresh(['signers', 'document']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function handlePostback(array $payload): bool
    {
        $action = (string) ($payload['action'] ?? '');
        $token = (string) ($payload['token'] ?? $payload['signing_token'] ?? '');

        if ($token === '') {
            return false;
        }

        $signing = DocumentSigning::query()
            ->where('dokobit_token', $token)
            ->first();

        if ($signing === null) {
            return false;
        }

        if ($action === 'signer_signed') {
            $signerId = (string) ($payload['signer_id'] ?? $payload['signer'] ?? '');
            if ($signerId !== '') {
                DocumentSigningSigner::query()
                    ->where('document_signing_id', $signing->getKey())
                    ->where('signer_key', $signerId)
                    ->whereNull('signed_at')
                    ->update(['signed_at' => now()]);
            }

            return true;
        }

        if ($action === 'signing_completed') {
            $fileUrl = (string) ($payload['file'] ?? '');
            if ($fileUrl === '') {
                self::syncStatus($signing);

                return true;
            }

            self::complete($signing, DokobitGatewayClient::make()->downloadSignedFile($fileUrl));

            return true;
        }

        return true;
    }

    public static function complete(DocumentSigning $signing, string $signedBinary): void
    {
        if ($signing->isCompleted()) {
            return;
        }

        DB::transaction(function () use ($signing, $signedBinary): void {
            $signing->loadMissing(['document.approvers', 'signers']);

            $document = $signing->document;
            if ($document instanceof Document) {
                $hash = hash('sha256', $signedBinary);
                $remoteName = SharepointDocumentUploader::remoteFileName(
                    $document,
                    'document-signed-'.$document->getKey().'.pdf',
                );

                DocumentBinaryStore::storeBinary($document->fresh(['documentType']), $signedBinary, $remoteName);

                $approvalsDone = DocumentWorkflow::internalApprovalsComplete($document);

                $payload = [
                    'file_path' => null,
                    'approved_file_path' => null,
                    'content_hash' => $hash,
                    'pdf_hash' => $hash,
                ];

                if ($approvalsDone) {
                    $payload['flag_approved'] = true;
                    $payload['user_approved_id'] = $signing->created_by;
                    $payload['approval_date'] = now();
                    $payload['confirmed_ip'] = request()->ip();
                    $payload['confirmed_user_agent'] = substr((string) request()->userAgent(), 0, 500);
                }

                $document->update($payload);
            }

            $signing->signers()
                ->whereNull('signed_at')
                ->update(['signed_at' => now()]);

            $signing->update([
                'status' => DocumentSigningStatus::Completed,
                'completed_at' => now(),
            ]);
        });

        if ($signing->document) {
            DocumentWorkflow::tryFinalize($signing->document->fresh(['approvers', 'documentSigning']));
        }
    }

    /**
     * @return array{options: array<int, string>, defaults: list<int>}
     */
    public static function signerSelectOptions(?User $currentUser = null): array
    {
        $options = User::query()
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'customer'))
            ->orderBy('name')
            ->orderBy('surname')
            ->get()
            ->mapWithKeys(function (User $user): array {
                $name = trim($user->fullName());
                $label = $name !== ''
                    ? (filled($user->email) ? $name.' ('.$user->email.')' : $name)
                    : (string) ($user->email ?? 'User #'.$user->getKey());

                return [$user->id => $label];
            })
            ->all();

        $defaults = [];
        if ($currentUser instanceof User && isset($options[$currentUser->getKey()])) {
            $defaults[] = (int) $currentUser->getKey();
        }

        return [
            'options' => $options,
            'defaults' => $defaults,
        ];
    }
}
