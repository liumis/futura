<?php

namespace App\Services;

use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeMonthlyPayment;
use App\Models\User;
use App\Notifications\DocumentApprovalRequestNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EmployeeBonusDocumentGenerator
{
    public const BONUS_ORDER_TYPE = 'Įsakymas dėl priedo';

    public const SHAREHOLDER_DECISION_TYPE = 'Akcininkų sprendimas';

    /**
     * Create or refresh bonus documents when a payment has a bonus amount.
     *
     * @return array{
     *     bonus_order: Document|null,
     *     shareholder_decision: Document|null,
     *     messages: list<string>
     * }
     */
    public static function syncForPayment(
        EmployeeMonthlyPayment $payment,
        ?User $actor = null,
        bool $forceRegenerate = false,
    ): array {
        $payment->loadMissing(['employee', 'bonusOrderDocument.approvedBy', 'shareholderDecisionDocument']);
        $bonus = round((float) ($payment->bonus_payment ?? 0), 2);

        if ($bonus <= 0) {
            return [
                'bonus_order' => null,
                'shareholder_decision' => null,
                'messages' => [],
            ];
        }

        $actor ??= auth()->user();
        $messages = [];
        $bonusOrder = self::syncBonusOrderDocument($payment, $actor, $bonus, $messages, $forceRegenerate);
        $shareholderDecision = null;

        if ((bool) ($payment->employee?->is_general_manager ?? false)) {
            $shareholderDecision = self::syncShareholderDecisionDocument($payment, $actor, $messages, $forceRegenerate);
        }

        $payment->forceFill([
            'bonus_order_document_id' => $bonusOrder?->getKey(),
            'shareholder_decision_document_id' => $shareholderDecision?->getKey(),
        ])->saveQuietly();

        return [
            'bonus_order' => $bonusOrder,
            'shareholder_decision' => $shareholderDecision,
            'messages' => $messages,
        ];
    }

    /**
     * @param  list<string>  $messages
     */
    protected static function syncBonusOrderDocument(
        EmployeeMonthlyPayment $payment,
        ?User $actor,
        float $bonus,
        array &$messages,
        bool $forceRegenerate = false,
    ): Document {
        $existing = $payment->bonusOrderDocument;
        $binary = EmployeeBonusOrderPdfGenerator::generate($payment);
        $hash = hash('sha256', $binary);

        $employeeName = $payment->employee?->fullName() ?: ('#'.$payment->employee_id);
        $period = $payment->payment_date?->format('Y-m') ?? now()->format('Y-m');
        $documentName = sprintf('Įsakymas dėl priedo — %s — %s', $employeeName, $period);
        $fileName = sprintf(
            'isakymas-priedas-%s-%s.pdf',
            Str::slug($employeeName, '-') ?: 'employee',
            $payment->getKey(),
        );

        $type = DocumentType::query()->firstOrCreate(
            ['name' => self::BONUS_ORDER_TYPE],
            ['group_by_year' => true],
        );

        if ($existing !== null) {
            if (! $forceRegenerate && $existing->content_hash === $hash) {
                if (! $existing->isApproved()) {
                    self::ensureGeneralManagerApprovers($existing, $payment->employee, $actor, $messages);
                }

                return $existing->fresh(['approvers', 'documentType', 'approvedBy']);
            }

            $storeBinary = self::prepareStoredBinary($existing, $binary, $fileName);
            $existing->forceFill([
                'document_date' => $payment->payment_date ?? $existing->document_date ?? now(),
                'name' => $documentName,
                'document_type_id' => $type->getKey(),
                'content_hash' => $hash,
                'pdf_hash' => hash('sha256', $storeBinary),
            ])->save();

            self::upload($existing->fresh(['documentType']), $storeBinary, $fileName, $payment->getKey(), 'Bonus order');

            if (! $existing->isApproved()) {
                self::ensureGeneralManagerApprovers($existing->fresh(['approvers']), $payment->employee, $actor, $messages);
            }

            ActivityLogger::logReportGenerated('Bonus order PDF', 'pdf', $payment, [
                'document_id' => $existing->getKey(),
                'bonus' => $bonus,
                'mode' => 'regenerated',
            ]);

            return $existing->fresh(['approvers', 'documentType', 'approvedBy']);
        }

        $document = Document::query()->create([
            'document_date' => $payment->payment_date ?? now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $actor instanceof User ? $actor->getKey() : null,
            'flag_approved' => false,
            'content_hash' => $hash,
            'pdf_hash' => $hash,
        ]);

        self::upload($document->fresh(['documentType']), $binary, $fileName, $payment->getKey(), 'Bonus order');

        self::ensureGeneralManagerApprovers($document->fresh(['approvers']), $payment->employee, $actor, $messages, notifyAll: true);

        ActivityLogger::logReportGenerated('Bonus order PDF', 'pdf', $payment, [
            'document_id' => $document->getKey(),
            'bonus' => $bonus,
            'mode' => 'created',
        ]);

        return $document->fresh(['approvers', 'documentType']);
    }

    /**
     * @param  list<string>  $messages
     */
    protected static function syncShareholderDecisionDocument(
        EmployeeMonthlyPayment $payment,
        ?User $actor,
        array &$messages,
        bool $forceRegenerate = false,
    ): Document {
        $existing = $payment->shareholderDecisionDocument;
        $binary = ShareholderBonusDecisionPdfGenerator::generate($payment);
        $hash = hash('sha256', $binary);

        $employeeName = $payment->employee?->fullName() ?: ('#'.$payment->employee_id);
        $period = $payment->payment_date?->format('Y-m') ?? now()->format('Y-m');
        $documentName = sprintf('Akcininkų sprendimas — %s — %s', $employeeName, $period);
        $fileName = sprintf(
            'akcininku-sprendimas-%s-%s.pdf',
            Str::slug($employeeName, '-') ?: 'employee',
            $payment->getKey(),
        );

        $type = DocumentType::query()->firstOrCreate(
            ['name' => self::SHAREHOLDER_DECISION_TYPE],
            ['group_by_year' => true],
        );

        if ($existing !== null) {
            if (! $forceRegenerate && $existing->content_hash === $hash) {
                return $existing;
            }

            $existing->forceFill([
                'document_date' => $payment->payment_date ?? $existing->document_date ?? now(),
                'name' => $documentName,
                'document_type_id' => $type->getKey(),
                'content_hash' => $hash,
                'pdf_hash' => $hash,
            ])->save();

            self::upload($existing->fresh(['documentType']), $binary, $fileName, $payment->getKey(), 'Shareholder decision');

            $messages[] = 'Akcininkų sprendimas PDF regenerated.';

            ActivityLogger::logReportGenerated('Shareholder bonus decision PDF', 'pdf', $payment, [
                'document_id' => $existing->getKey(),
                'mode' => 'regenerated',
            ]);

            return $existing->fresh(['documentType']);
        }

        $document = Document::query()->create([
            'document_date' => $payment->payment_date ?? now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $actor instanceof User ? $actor->getKey() : null,
            'flag_approved' => false,
            'content_hash' => $hash,
            'pdf_hash' => $hash,
        ]);

        self::upload($document->fresh(['documentType']), $binary, $fileName, $payment->getKey(), 'Shareholder decision');

        $messages[] = 'Akcininkų sprendimas saved (external signing; no notifications sent).';

        ActivityLogger::logReportGenerated('Shareholder bonus decision PDF', 'pdf', $payment, [
            'document_id' => $document->getKey(),
            'mode' => 'created',
        ]);

        return $document->fresh(['documentType']);
    }

    protected static function prepareStoredBinary(Document $existing, string $binary, string &$fileName): string
    {
        if (! $existing->isApproved()) {
            return $binary;
        }

        $approver = $existing->approvedBy;
        if (! $approver instanceof User) {
            return $binary;
        }

        try {
            $stamped = DocumentApprover::stampPdf($binary, $approver);
            $base = pathinfo($fileName, PATHINFO_FILENAME) ?: 'document';
            $fileName = $base.'-approved.pdf';

            return $stamped;
        } catch (Throwable $exception) {
            Log::warning('Could not re-stamp regenerated bonus PDF; storing unstamped', [
                'document_id' => $existing->getKey(),
                'message' => $exception->getMessage(),
            ]);

            return $binary;
        }
    }

    protected static function upload(
        Document $document,
        string $binary,
        string $fileName,
        int $paymentId,
        string $label,
    ): void {
        try {
            DocumentBinaryStore::storeBinary($document, $binary, $fileName);
        } catch (Throwable $exception) {
            Log::warning($label.' SharePoint upload failed', [
                'payment_id' => $paymentId,
                'document_id' => $document->getKey(),
                'message' => $exception->getMessage(),
            ]);

            throw new RuntimeException(
                $label.' PDF storage failed: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    protected static function ensureGeneralManagerApprovers(
        Document $document,
        ?Employee $paymentEmployee,
        ?User $actor,
        array &$messages,
        bool $notifyAll = false,
    ): void {
        if ($document->isApproved()) {
            return;
        }

        $approvers = self::generalManagerUsers($paymentEmployee);
        if ($approvers->isEmpty()) {
            $messages[] = 'No general manager users found for bonus order approval.';

            return;
        }

        $creatorId = $actor instanceof User ? (int) $actor->getKey() : null;
        $existingIds = $document->approvers()->pluck('users.id')->map(fn ($id): int => (int) $id)->all();
        $newlyAdded = [];

        foreach ($approvers as $approver) {
            if (in_array((int) $approver->getKey(), $existingIds, true)) {
                continue;
            }

            $isCreator = $creatorId !== null && (int) $approver->getKey() === $creatorId;
            $document->approvers()->syncWithoutDetaching([
                $approver->getKey() => [
                    'approved_at' => $isCreator ? now() : null,
                    'is_auto_approved' => $isCreator,
                ],
            ]);
            $newlyAdded[] = $approver;
        }

        $document->load('approvers');

        if ($newlyAdded !== [] || $notifyAll) {
            self::notifyGeneralManagers(
                $document,
                $notifyAll ? $document->approvers->filter(fn (User $u): bool => blank($u->pivot?->approved_at))->all() : $newlyAdded,
            );
            if ($newlyAdded !== [] || $notifyAll) {
                $messages[] = 'General managers notified to confirm bonus order.';
            }
        }
    }

    /**
     * @param  list<User>|array<int, User>  $recipients
     */
    protected static function notifyGeneralManagers(Document $document, array $recipients): void
    {
        if ($recipients === []) {
            return;
        }

        $url = DocumentResource::getUrl('edit', ['record' => $document]);

        foreach ($recipients as $approver) {
            if (filled($approver->pivot?->approved_at ?? null)) {
                continue;
            }

            try {
                $approver->notify(new DocumentApprovalRequestNotification(
                    documentId: (int) $document->getKey(),
                    documentName: (string) $document->name,
                    url: $url,
                ));
            } catch (Throwable $exception) {
                Log::warning('Bonus order in-app notification failed', [
                    'document_id' => $document->getKey(),
                    'user_id' => $approver->getKey(),
                    'message' => $exception->getMessage(),
                ]);
            }

            self::sendApprovalEmail($approver, $document, $url);
        }
    }

    /**
     * @return Collection<int, User>
     */
    public static function generalManagerUsers(?Employee $excludeEmployee = null): Collection
    {
        $excludeUserId = filled($excludeEmployee?->user_id) ? (int) $excludeEmployee->user_id : null;

        return User::query()
            ->whereHas('employee', fn ($query) => $query->where('is_general_manager', true))
            ->whereDoesntHave('roles', fn ($query) => $query->where('name', 'customer'))
            ->when($excludeUserId !== null, fn ($query) => $query->whereKeyNot($excludeUserId))
            ->get();
    }

    protected static function sendApprovalEmail(User $recipient, Document $document, string $url): void
    {
        $email = trim((string) ($recipient->email ?? ''));
        if ($email === '') {
            return;
        }

        $subject = 'Bonus order awaiting confirmation: '.$document->name;
        $body = implode("\n", [
            'A bonus order (Įsakymas dėl priedo) needs your confirmation as general manager.',
            '',
            'Document: '.$document->name,
            '',
            'Open the document to confirm:',
            $url,
        ]);

        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) (config('mail.from.name') ?: $fromAddress);

        if (EmailTestMode::isEnabled()) {
            EmailLogWriter::logManual(
                to: $email,
                subject: $subject,
                body: $body,
                from: sprintf('%s <%s>', $fromName, $fromAddress),
                bodyAppendix: EmailTestMode::blockedMessage(),
            );

            return;
        }

        try {
            Mail::raw($body, function ($message) use ($email, $subject, $fromAddress, $fromName): void {
                $message->to($email)->subject($subject);
                $message->from($fromAddress, $fromName);
            });
        } catch (Throwable $exception) {
            Log::warning('Bonus order approval email failed', [
                'document_id' => $document->getKey(),
                'email' => $email,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
