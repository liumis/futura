<?php

namespace App\Services;

use App\Enums\DividendPaymentReportStatus;
use App\Enums\DividendPaymentStatus;
use App\Filament\Admin\Resources\Dividends\DividendResource;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Dividend;
use App\Models\DividendPaymentReport;
use App\Models\User;
use App\Notifications\DividendPaymentReportApprovalNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class DividendPaymentReportApprover
{
    /**
     * @param  Collection<int, Dividend>|list<int>  $payments
     * @param  list<int>  $approverUserIds
     */
    public static function create(Collection|array $payments, array $approverUserIds, User $creator): DividendPaymentReport
    {
        $paymentModels = $payments instanceof Collection
            ? $payments
            : Dividend::query()->with(['employee'])->whereIn('id', $payments)->get();

        if ($paymentModels->isEmpty()) {
            throw new RuntimeException('Select at least one dividend.');
        }

        $paymentModels->loadMissing(['employee']);

        $calculator = app(LithuanianDividendCalculator::class);
        foreach ($paymentModels as $payment) {
            if ($payment->gpm_amount === null || $payment->net_amount === null) {
                $tax = $calculator->calculate((float) $payment->amount);
                $payment->applyTaxSnapshot([
                    'gpm' => $tax['gpm'],
                    'net' => $tax['net'],
                ]);
            }
        }

        $alreadyReported = $paymentModels->first(
            fn (Dividend $payment): bool => filled($payment->dividend_payment_report_id),
        );

        if ($alreadyReported instanceof Dividend) {
            throw new RuntimeException('One or more selected dividends are already included in a report.');
        }

        $approverIds = collect($approverUserIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($approverIds->isEmpty()) {
            throw new RuntimeException('Select at least one approver.');
        }

        $approvers = User::query()
            ->whereIn('id', $approverIds)
            ->whereDoesntHave('roles', fn ($q) => $q->where('name', 'customer'))
            ->get();

        if ($approvers->isEmpty()) {
            throw new RuntimeException('No valid approvers selected.');
        }

        return DB::transaction(function () use ($paymentModels, $approvers, $creator): DividendPaymentReport {
            $report = DividendPaymentReport::query()->create([
                'name' => 'Dividend payment report '.now()->format('Y-m-d H:i:s'),
                'status' => DividendPaymentReportStatus::Created,
                'created_by' => $creator->getKey(),
            ]);

            Dividend::query()
                ->whereIn('id', $paymentModels->modelKeys())
                ->whereNull('dividend_payment_report_id')
                ->update(['dividend_payment_report_id' => $report->getKey()]);

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

    public static function confirmBy(DividendPaymentReport $report, User $user): void
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

            if ($report->fresh()->status === DividendPaymentReportStatus::Confirmed) {
                self::markDocumentApproved($report->fresh(['document']), $user);
            }
        });
    }

    protected static function storeDocument(DividendPaymentReport $report, User $creator): Document
    {
        $binary = DividendPaymentReportPdfGenerator::generate($report);

        $type = DocumentType::query()->firstOrCreate(
            ['name' => 'Dividend payment report'],
            [],
        );

        $document = Document::query()->create([
            'document_date' => now(),
            'name' => $report->name,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $creator->getKey(),
            'flag_approved' => $report->status === DividendPaymentReportStatus::Confirmed,
            'user_approved_id' => $report->status === DividendPaymentReportStatus::Confirmed
                ? $creator->getKey()
                : null,
            'approval_date' => $report->status === DividendPaymentReportStatus::Confirmed ? now() : null,
            'content_hash' => hash('sha256', $binary),
            'pdf_hash' => hash('sha256', $binary),
        ]);

        DocumentBinaryStore::storeBinary(
            $document->fresh(['documentType']),
            $binary,
            'dividend-payment-report-'.$report->getKey().'.pdf',
        );

        ActivityLogger::logReportGenerated('Dividend payment report PDF', 'pdf', $report, [
            'document_id' => $document->getKey(),
        ]);

        return $document->fresh(['documentType']);
    }

    protected static function markDocumentApproved(DividendPaymentReport $report, User $user): void
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

    protected static function notifyPendingApprovers(DividendPaymentReport $report): void
    {
        $url = DividendResource::getUrl('index');

        foreach ($report->approvers as $approver) {
            if (filled($approver->pivot?->approved_at)) {
                continue;
            }

            try {
                $approver->notify(new DividendPaymentReportApprovalNotification(
                    reportId: (int) $report->getKey(),
                    reportName: (string) $report->name,
                    url: $url,
                ));
            } catch (Throwable $e) {
                report($e);
            }
        }
    }
}

