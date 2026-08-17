<?php

namespace App\Services;

use App\Enums\EmployeeMonthlyPaymentStatus;
use App\Enums\EmployeePaymentReportStatus;
use App\Filament\Admin\Pages\MonthlyPayment;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmployeeMonthlyPayment;
use App\Models\EmployeePaymentReport;
use App\Models\User;
use App\Notifications\PaymentReportApprovalNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use RuntimeException;
use Throwable;

class EmployeePaymentReportApprover
{
    /**
     * @param  Collection<int, EmployeeMonthlyPayment>|list<int>  $payments
     * @param  list<int>  $approverUserIds
     */
    public static function create(Collection|array $payments, array $approverUserIds, User $creator): EmployeePaymentReport
    {
        $paymentModels = $payments instanceof Collection
            ? $payments
            : EmployeeMonthlyPayment::query()->with('employee')->whereIn('id', $payments)->get();

        if ($paymentModels->isEmpty()) {
            throw new RuntimeException('Select at least one payment.');
        }

        $alreadyReported = $paymentModels->first(
            fn (EmployeeMonthlyPayment $payment): bool => filled($payment->employee_payment_report_id),
        );

        if ($alreadyReported instanceof EmployeeMonthlyPayment) {
            throw new RuntimeException('One or more selected payments are already included in a report.');
        }

        $approverIds = collect($approverUserIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($approverIds->isEmpty()) {
            throw new RuntimeException('Select at least one person for approval.');
        }

        $approvers = User::query()
            ->whereIn('id', $approverIds)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'customer'))
            ->get();

        if ($approvers->isEmpty()) {
            throw new RuntimeException('No valid approvers selected.');
        }

        return DB::transaction(function () use ($paymentModels, $approvers, $creator): EmployeePaymentReport {
            $report = EmployeePaymentReport::query()->create([
                'name' => 'Payment report '.now()->format('Y-m-d H:i:s'),
                'status' => EmployeePaymentReportStatus::Created,
                'created_by' => $creator->getKey(),
            ]);

            EmployeeMonthlyPayment::query()
                ->whereIn('id', $paymentModels->modelKeys())
                ->whereNull('employee_payment_report_id')
                ->update(['employee_payment_report_id' => $report->getKey()]);

            $creatorId = (int) $creator->getKey();
            $sync = [];

            foreach ($approvers as $approver) {
                $isCreator = (int) $approver->getKey() === $creatorId;
                $sync[$approver->getKey()] = [
                    'approved_at' => $isCreator ? now() : null,
                    'is_auto_approved' => $isCreator,
                ];
            }

            $report->approvers()->sync($sync);
            $report->load(['payments.employee', 'approvers', 'creator']);
            $report->refreshStatus();

            $document = self::storeDocument($report, $creator);
            $report->update(['document_id' => $document->getKey()]);

            self::notifyPendingApprovers($report->fresh(['approvers', 'creator']));

            return $report->fresh(['document', 'approvers', 'payments']);
        });
    }

    public static function confirmBy(EmployeePaymentReport $report, User $user): void
    {
        if (! $report->userHasPendingApproval((int) $user->getKey())) {
            throw new RuntimeException('You have no pending confirmation for this report.');
        }

        DB::transaction(function () use ($report, $user): void {
            $report->approvers()->updateExistingPivot($user->getKey(), [
                'approved_at' => now(),
                'is_auto_approved' => false,
            ]);

            $report->refreshStatus();

            if ($report->fresh()->status === EmployeePaymentReportStatus::Confirmed) {
                self::markDocumentApproved($report->fresh(['document']), $user);
            }
        });
    }

    protected static function storeDocument(EmployeePaymentReport $report, User $creator): Document
    {
        $binary = EmployeePaymentReportPdfGenerator::generate($report);

        $type = DocumentType::query()->firstOrCreate(
            ['name' => 'Monthly payment report'],
            [],
        );

        $document = Document::query()->create([
            'document_date' => now(),
            'name' => $report->name,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $creator->getKey(),
            'flag_approved' => $report->status === EmployeePaymentReportStatus::Confirmed,
            'user_approved_id' => $report->status === EmployeePaymentReportStatus::Confirmed
                ? $creator->getKey()
                : null,
            'approval_date' => $report->status === EmployeePaymentReportStatus::Confirmed ? now() : null,
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        DocumentBinaryStore::storeBinary(
            $document->fresh(['documentType']),
            $binary,
            'payment-report-'.$report->getKey().'.pdf',
        );

        ActivityLogger::logReportGenerated('Monthly payment report PDF', 'pdf', $report, [
            'document_id' => $document->getKey(),
        ]);

        return $document->fresh(['documentType']);
    }

    protected static function markDocumentApproved(EmployeePaymentReport $report, User $user): void
    {
        $document = $report->document;
        if (! $document || $document->isApproved()) {
            return;
        }

        $document->update([
            'flag_approved' => true,
            'user_approved_id' => $user->getKey(),
            'approval_date' => now(),
            'confirmed_ip' => request()->ip(),
            'confirmed_user_agent' => substr((string) request()->userAgent(), 0, 500),
        ]);
    }

    protected static function notifyPendingApprovers(EmployeePaymentReport $report): void
    {
        $url = MonthlyPayment::getUrl();

        foreach ($report->approvers as $approver) {
            if (filled($approver->pivot?->approved_at)) {
                continue;
            }

            try {
                $approver->notify(new PaymentReportApprovalNotification(
                    reportId: (int) $report->getKey(),
                    reportName: (string) $report->name,
                    url: $url,
                ));
            } catch (Throwable $e) {
                report($e);
            }

            self::sendEmail($approver, $report, $url);
        }
    }

    protected static function sendEmail(User $recipient, EmployeePaymentReport $report, string $url): void
    {
        $email = trim((string) ($recipient->email ?? ''));
        if ($email === '') {
            return;
        }

        $subject = 'Payment report awaiting confirmation: '.$report->name;
        $body = implode("\n", [
            'A payment report needs your confirmation.',
            '',
            'Report: '.$report->name,
            'Created by: '.($report->creator?->fullName() ?: $report->creator?->email ?: '—'),
            '',
            'Open the monthly payment page to confirm:',
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
        } catch (Throwable $e) {
            report($e);
        }
    }
}
