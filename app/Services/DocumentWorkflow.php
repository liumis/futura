<?php

namespace App\Services;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Mail\DokobitExternalSigningInviteMail;
use App\Models\Document;
use App\Models\DocumentSigning;
use App\Models\User;
use App\Notifications\DocumentApprovalRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class DocumentWorkflow
{
    /**
     * Assign internal approvers and/or start Dokobit signing (internal + external invitees).
     *
     * @param  list<int|string>  $approverUserIds
     * @param  list<int|string>  $signerUserIds
     * @param  list<array{name?: string, surname?: string, email?: string}>  $externalInvitees
     * @return array{document: Document, signing: DocumentSigning|null}
     */
    public static function start(
        Document $document,
        User $creator,
        array $approverUserIds = [],
        array $signerUserIds = [],
        array $externalInvitees = [],
    ): array {
        if ($document->paymentReport !== null || $document->dividendPaymentReport !== null) {
            throw new RuntimeException('Payment report documents use their own approval flow.');
        }

        if ($document->awaitsDokobitSignature() && (collect($signerUserIds)->filter()->isNotEmpty() || collect($externalInvitees)->filter()->isNotEmpty())) {
            throw new RuntimeException('Dokobit signing is already in progress. You can still add internal approvers.');
        }

        $approverIds = collect($approverUserIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $signerIds = collect($signerUserIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        $externals = collect($externalInvitees)
            ->map(function (array $row): ?array {
                $email = strtolower(trim((string) ($row['email'] ?? '')));
                $name = trim((string) ($row['name'] ?? ''));
                $surname = trim((string) ($row['surname'] ?? ''));

                if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    return null;
                }

                return [
                    'name' => $name !== '' ? $name : 'Signer',
                    'surname' => $surname !== '' ? $surname : '-',
                    'email' => $email,
                ];
            })
            ->filter()
            ->unique('email')
            ->values();

        if ($approverIds->isEmpty() && $signerIds->isEmpty() && $externals->isEmpty()) {
            throw new RuntimeException('Select at least one internal approver, internal signer, or external invitee.');
        }

        $existingApproverIds = $document->approvers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $newApproverIds = $approverIds->reject(fn (int $id): bool => in_array($id, $existingApproverIds, true))->values();

        if (
            $newApproverIds->isEmpty()
            && $signerIds->isEmpty()
            && $externals->isEmpty()
        ) {
            throw new RuntimeException('Those approvers are already assigned. Select someone new, or add Dokobit signers.');
        }

        $signing = null;
        $wasApproved = $document->isApproved();

        DB::transaction(function () use ($document, $creator, $newApproverIds, $signerIds, $externals, $wasApproved, &$signing): void {
            if ($wasApproved && ($newApproverIds->isNotEmpty() || $signerIds->isNotEmpty() || $externals->isNotEmpty())) {
                $document->forceFill([
                    'flag_approved' => false,
                    'user_approved_id' => null,
                    'approval_date' => null,
                ])->save();
            }

            if ($newApproverIds->isNotEmpty()) {
                $approvers = User::query()
                    ->whereIn('id', $newApproverIds)
                    ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'customer'))
                    ->get();

                if ($approvers->isEmpty()) {
                    throw new RuntimeException('No valid internal approvers selected.');
                }

                $creatorId = (int) $creator->getKey();

                foreach ($approvers as $approver) {
                    $isCreator = (int) $approver->getKey() === $creatorId;
                    $document->approvers()->syncWithoutDetaching([
                        $approver->getKey() => [
                            'approved_at' => $isCreator ? now() : null,
                            'is_auto_approved' => $isCreator,
                        ],
                    ]);
                }
            }

            if (($signerIds->isNotEmpty() || $externals->isNotEmpty()) && ! $document->fresh()->awaitsDokobitSignature()) {
                $signing = DocumentDokobitSigner::start(
                    $document->fresh(['documentType']),
                    $signerIds->all(),
                    $creator,
                    $externals->all(),
                );
            }
        });

        $document = $document->fresh([
            'approvers',
            'documentSigning.signers',
        ]);

        self::notifyPendingApprovers($document);
        self::tryFinalize($document, $creator);

        if ($signing !== null) {
            self::sendExternalInvites($signing->fresh(['signers', 'document']));
        }

        return [
            'document' => $document->fresh([
                'approvers',
                'documentSigning.signers',
                'paymentReport',
                'dividendPaymentReport',
            ]),
            'signing' => $signing?->fresh(['signers', 'document']),
        ];
    }

    /**
     * Record the current user's approval when they are not yet on the approvers list.
     */
    public static function approveAsParticipant(Document $document, User $user): Document
    {
        if ($document->isApproved()) {
            throw new RuntimeException('Document is already approved and locked.');
        }

        if ($document->userHasPendingApproval((int) $user->getKey())) {
            return self::confirmApproval($document, $user);
        }

        if ($document->currentUserHasApproved((int) $user->getKey())) {
            throw new RuntimeException('You have already approved this document.');
        }

        if (! $document->hasDocumentApprovers()) {
            return DocumentApprover::approve(
                $document,
                $user,
                (string) request()->ip(),
                (string) request()->userAgent(),
            );
        }

        $document->approvers()->syncWithoutDetaching([
            $user->getKey() => [
                'approved_at' => now(),
                'is_auto_approved' => false,
            ],
        ]);

        return self::tryFinalize($document->fresh(['approvers', 'documentSigning', 'approvedBy']), $user);
    }

    public static function confirmApproval(Document $document, User $user): Document
    {
        if ($document->isApproved()) {
            throw new RuntimeException('Document is already approved.');
        }

        if (! $document->userHasPendingApproval((int) $user->getKey())) {
            throw new RuntimeException('You have no pending approval for this document.');
        }

        $document->approvers()->updateExistingPivot($user->getKey(), [
            'approved_at' => now(),
            'is_auto_approved' => false,
        ]);

        return self::tryFinalize($document->fresh(['approvers', 'documentSigning', 'approvedBy']), $user);
    }

    public static function tryFinalize(Document $document, ?User $actor = null): Document
    {
        $document->loadMissing(['approvers', 'documentSigning', 'approvedBy']);

        if ($document->isApproved()) {
            return $document;
        }

        if (! self::internalApprovalsComplete($document)) {
            return $document;
        }

        if (! self::dokobitSigningCompleteOrAbsent($document)) {
            return $document;
        }

        $actor ??= $document->approvers
            ->filter(fn (User $user): bool => filled($user->pivot?->approved_at))
            ->sortByDesc(fn (User $user) => $user->pivot?->approved_at)
            ->first()
            ?? auth()->user();

        if (! $actor instanceof User) {
            return $document;
        }

        // Dokobit already stored the signed PDF — lock without re-stamping.
        if (
            $document->documentSigning?->isCompleted()
            && filled($document->file_path)
            && filled($document->content_hash)
        ) {
            $document->update([
                'flag_approved' => true,
                'user_approved_id' => $document->user_approved_id ?: $actor->getKey(),
                'approval_date' => $document->approval_date ?: now(),
                'approved_file_path' => $document->approved_file_path ?: $document->file_path,
                'confirmed_ip' => $document->confirmed_ip ?: request()->ip(),
                'confirmed_user_agent' => $document->confirmed_user_agent
                    ?: substr((string) request()->userAgent(), 0, 500),
            ]);

            return $document->fresh(['approvers', 'documentSigning', 'approvedBy']);
        }

        return DocumentApprover::approve(
            $document,
            $actor,
            (string) request()->ip(),
            (string) request()->userAgent(),
        );
    }

    public static function internalApprovalsComplete(Document $document): bool
    {
        $document->loadMissing('approvers');

        if ($document->approvers->isEmpty()) {
            return true;
        }

        return ! $document->approvers->contains(
            fn (User $user): bool => blank($user->pivot?->approved_at),
        );
    }

    public static function dokobitSigningCompleteOrAbsent(Document $document): bool
    {
        $signing = $document->documentSigning;

        if ($signing === null) {
            return true;
        }

        return $signing->isCompleted();
    }

    public static function notifyPendingApprovers(Document $document): void
    {
        $document->loadMissing('approvers');
        $url = DocumentResource::getUrl('edit', ['record' => $document]);

        foreach ($document->approvers as $approver) {
            if (filled($approver->pivot?->approved_at)) {
                continue;
            }

            try {
                $approver->notify(new DocumentApprovalRequestNotification(
                    documentId: (int) $document->getKey(),
                    documentName: (string) $document->name,
                    url: $url,
                ));
            } catch (Throwable $exception) {
                Log::warning('Document approval notification failed', [
                    'document_id' => $document->getKey(),
                    'user_id' => $approver->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    public static function sendExternalInvites(?DocumentSigning $signing): void
    {
        if ($signing === null) {
            return;
        }

        $signing->loadMissing(['signers', 'document']);

        foreach ($signing->signers as $signer) {
            if (! $signer->is_external || blank($signer->email) || blank($signer->signing_url)) {
                continue;
            }

            try {
                Mail::to((string) $signer->email)->send(new DokobitExternalSigningInviteMail(
                    document: $signing->document,
                    signer: $signer,
                    signingUrl: (string) $signer->signing_url,
                ));

                $signer->update(['invited_at' => now()]);
            } catch (Throwable $exception) {
                Log::warning('Dokobit external invite email failed', [
                    'signer_id' => $signer->getKey(),
                    'email' => $signer->email,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }
}
