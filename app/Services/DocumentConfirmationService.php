<?php

namespace App\Services;

use App\Enums\DocumentConfirmationAudience;
use App\Enums\EmployeeStatus;
use App\Models\Document;
use App\Models\DocumentConfirmation;
use App\Models\Employee;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DocumentConfirmationService
{
    /**
     * Resolve users who must confirm this document.
     *
     * @return Collection<int, User>
     */
    public function resolveAudience(Document $document): Collection
    {
        $document->loadMissing('documentType', 'confirmationUsers');

        if (! $this->requiresRevalidation($document)) {
            return collect();
        }

        $audience = $document->confirmation_audience
            ?? DocumentConfirmationAudience::AllActiveEmployees;

        if ($audience === DocumentConfirmationAudience::None) {
            return collect();
        }

        if ($audience === DocumentConfirmationAudience::SpecificUsers) {
            return $document->confirmationUsers
                ->unique('id')
                ->values();
        }

        return Employee::query()
            ->where('status', EmployeeStatus::Active)
            ->whereNotNull('user_id')
            ->with('user')
            ->get()
            ->pluck('user')
            ->filter()
            ->unique('id')
            ->values();
    }

    public function requiresRevalidation(Document $document): bool
    {
        $document->loadMissing('documentType');

        $months = (int) ($document->documentType?->periodical_signature_months ?? 0);

        return $months > 0;
    }

    public function revalidationMonths(Document $document): int
    {
        $document->loadMissing('documentType');

        return max(0, (int) ($document->documentType?->periodical_signature_months ?? 0));
    }

    /**
     * Create first-cycle confirmations for a newly created document (due tomorrow).
     */
    public function enrollDocument(Document $document, ?CarbonInterface $now = null): int
    {
        if (! $this->requiresRevalidation($document)) {
            return 0;
        }

        $now = $now ?? now();
        $dueAt = $now->copy()->startOfDay()->addDay();
        $created = 0;

        foreach ($this->resolveAudience($document) as $user) {
            if ($this->ensureOpenCycle($document, $user, $dueAt)) {
                $created++;
            }
        }

        return $created;
    }

    /**
     * Enroll a user into open cycles for all revalidation documents (new joiner).
     */
    public function enrollUser(User $user, ?CarbonInterface $now = null): int
    {
        $now = $now ?? now();
        $dueAt = $now->copy()->startOfDay()->addDay();
        $created = 0;

        $documents = Document::query()
            ->with(['documentType', 'confirmationUsers'])
            ->whereHas('documentType', fn ($q) => $q->where('periodical_signature_months', '>', 0))
            ->get();

        foreach ($documents as $document) {
            if (! $this->userIsInAudience($document, $user)) {
                continue;
            }

            if ($this->ensureOpenCycle($document, $user, $dueAt)) {
                $created++;
            }
        }

        return $created;
    }

    public function userIsInAudience(Document $document, User $user): bool
    {
        return $this->resolveAudience($document)->contains(fn (User $u): bool => (int) $u->getKey() === (int) $user->getKey());
    }

    /**
     * Ensure the user has an open (unconfirmed) cycle. Does not create a duplicate
     * if one already exists. Uses next cycle index when previous cycles exist.
     */
    public function ensureOpenCycle(
        Document $document,
        User $user,
        CarbonInterface $dueAt,
        ?int $cycleIndex = null,
    ): bool {
        $existingOpen = DocumentConfirmation::query()
            ->where('document_id', $document->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('confirmed_at')
            ->exists();

        if ($existingOpen) {
            return false;
        }

        if ($cycleIndex === null) {
            $max = (int) DocumentConfirmation::query()
                ->where('document_id', $document->getKey())
                ->where('user_id', $user->getKey())
                ->max('cycle_index');

            $cycleIndex = $max + 1;
            if ($cycleIndex < 1) {
                $cycleIndex = 1;
            }
        }

        DocumentConfirmation::query()->firstOrCreate(
            [
                'document_id' => $document->getKey(),
                'user_id' => $user->getKey(),
                'cycle_index' => $cycleIndex,
            ],
            [
                'due_at' => $dueAt->toDateString(),
            ],
        );

        return true;
    }

    /**
     * Mark confirmation as done and schedule the next personal cycle.
     */
    public function confirm(
        DocumentConfirmation $confirmation,
        ?User $actor = null,
        ?string $ip = null,
        ?string $attachmentPath = null,
        ?string $attachmentOriginalName = null,
    ): DocumentConfirmation {
        if ($confirmation->isConfirmed()) {
            return $confirmation;
        }

        $confirmation->loadMissing('document.documentType');

        if ($this->requiresAttachment($confirmation) && blank($attachmentPath)) {
            throw ValidationException::withMessages([
                'attachment' => 'An upload is required to confirm this document.',
            ]);
        }

        return DB::transaction(function () use ($confirmation, $ip, $attachmentPath, $attachmentOriginalName): DocumentConfirmation {
            $confirmation->confirmed_at = now();
            $confirmation->confirmed_ip = $ip;
            $confirmation->attachment_path = $attachmentPath;
            $confirmation->attachment_original_name = $attachmentOriginalName;
            $confirmation->save();

            $document = $confirmation->document()->with('documentType')->first();
            $user = $confirmation->user;

            if ($document === null || $user === null || ! $this->requiresRevalidation($document)) {
                return $confirmation->fresh();
            }

            $months = $this->revalidationMonths($document);
            $nextDue = $confirmation->confirmed_at->copy()->startOfDay()->addMonthsNoOverflow($months);

            $this->ensureOpenCycle(
                $document,
                $user,
                $nextDue,
                cycleIndex: ((int) $confirmation->cycle_index) + 1,
            );

            return $confirmation->fresh();
        });
    }

    public function pendingForUser(User $user, Document $document): ?DocumentConfirmation
    {
        return DocumentConfirmation::query()
            ->where('document_id', $document->getKey())
            ->where('user_id', $user->getKey())
            ->whereNull('confirmed_at')
            ->orderBy('cycle_index')
            ->first();
    }

    public function requiresAttachment(DocumentConfirmation $confirmation): bool
    {
        $confirmation->loadMissing('document.documentType');

        return (bool) ($confirmation->document?->documentType?->confirmation_attachment_required ?? false);
    }
}
