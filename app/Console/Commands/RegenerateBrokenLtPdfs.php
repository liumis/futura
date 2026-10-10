<?php

namespace App\Console\Commands;

use App\Models\EmployeeMonthlyPayment;
use App\Models\LeaveRequest;
use App\Models\SharepointSetting;
use App\Services\EmployeeBonusDocumentGenerator;
use App\Services\EmployeePayslipDocumentGenerator;
use App\Services\LeaveRequestDocumentGenerator;
use App\Services\LeaveRequestOrderDocumentGenerator;
use Illuminate\Console\Command;
use Throwable;

class RegenerateBrokenLtPdfs extends Command
{
    protected $signature = 'app:regenerate-broken-lt-pdfs
                            {--dry-run : Report what would be regenerated without uploading}
                            {--only= : Comma list: bonus,shareholder,leave-prasymas,leave-isakymas,payslip (default: all)}
                            {--limit= : Process at most N records per category}';

    protected $description = 'Re-render stored LT DomPDF documents (bonus orders, leave docs, payslips) after the font-weight/ė fix';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = filled($this->option('limit')) ? max(1, (int) $this->option('limit')) : null;
        $only = $this->parseOnly((string) ($this->option('only') ?? ''));

        if (! $dryRun && ! SharepointSetting::instance()->isReady()) {
            $this->error('SharePoint is not ready. Enable and configure System → SharePoint first (or use --dry-run).');

            return self::FAILURE;
        }

        $totals = [
            'ok' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        if ($only['bonus'] || $only['shareholder']) {
            $this->regenerateBonusDocuments($only, $dryRun, $limit, $totals);
        }

        if ($only['leave-prasymas']) {
            $this->regenerateLeavePrasymas($dryRun, $limit, $totals);
        }

        if ($only['leave-isakymas']) {
            $this->regenerateLeaveIsakymas($dryRun, $limit, $totals);
        }

        if ($only['payslip']) {
            $this->regeneratePayslips($dryRun, $limit, $totals);
        }

        $this->newLine();
        $this->info(sprintf(
            'Done. Regenerated %d, skipped %d, failed %d%s.',
            $totals['ok'],
            $totals['skipped'],
            $totals['failed'],
            $dryRun ? ' (dry-run)' : '',
        ));

        return $totals['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array{bonus: bool, shareholder: bool, leave-prasymas: bool, leave-isakymas: bool, payslip: bool}  $only
     * @param  array{ok: int, skipped: int, failed: int}  $totals
     */
    protected function regenerateBonusDocuments(array $only, bool $dryRun, ?int $limit, array &$totals): void
    {
        $query = EmployeeMonthlyPayment::query()
            ->with(['employee', 'bonusOrderDocument', 'shareholderDecisionDocument'])
            ->where(function ($q) use ($only): void {
                if ($only['bonus']) {
                    $q->orWhereNotNull('bonus_order_document_id');
                }
                if ($only['shareholder']) {
                    $q->orWhereNotNull('shareholder_decision_document_id');
                }
            })
            ->where('bonus_payment', '>', 0)
            ->orderBy('id');

        $processed = 0;
        $query->chunkById(25, function ($chunk) use ($only, $dryRun, $limit, &$totals, &$processed): bool {
            foreach ($chunk as $payment) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }
                $processed++;

                $label = 'payment #'.$payment->getKey();
                if ($dryRun) {
                    $parts = [];
                    if ($only['bonus'] && filled($payment->bonus_order_document_id)) {
                        $parts[] = 'bonus doc #'.$payment->bonus_order_document_id;
                    }
                    if ($only['shareholder'] && filled($payment->shareholder_decision_document_id)) {
                        $parts[] = 'shareholder doc #'.$payment->shareholder_decision_document_id;
                    }
                    if ($parts === []) {
                        $totals['skipped']++;
                        $this->line($label.' — skip (nothing linked)');

                        continue;
                    }
                    $totals['ok']++;
                    $this->line($label.' — would regenerate '.implode(', ', $parts));

                    continue;
                }

                try {
                    $result = EmployeeBonusDocumentGenerator::syncForPayment($payment, forceRegenerate: true);
                    $totals['ok']++;
                    $this->info($label.' — regenerated'
                        .($result['bonus_order'] ? ' bonus #'.$result['bonus_order']->getKey() : '')
                        .($result['shareholder_decision'] ? ' shareholder #'.$result['shareholder_decision']->getKey() : ''));
                } catch (Throwable $exception) {
                    $totals['failed']++;
                    $this->error($label.' failed: '.$exception->getMessage());
                }
            }

            return true;
        });
    }

    /**
     * @param  array{ok: int, skipped: int, failed: int}  $totals
     */
    protected function regenerateLeavePrasymas(bool $dryRun, ?int $limit, array &$totals): void
    {
        $query = LeaveRequest::query()
            ->with(['employee', 'leaveRequestType', 'document'])
            ->whereNotNull('document_id')
            ->orderBy('id');

        $processed = 0;
        $query->chunkById(25, function ($chunk) use ($dryRun, $limit, &$totals, &$processed): bool {
            foreach ($chunk as $leave) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }
                $processed++;

                $label = 'leave #'.$leave->getKey().' prašymas #'.$leave->document_id;
                if ($dryRun) {
                    $totals['ok']++;
                    $this->line($label.' — would regenerate');

                    continue;
                }

                try {
                    $document = LeaveRequestDocumentGenerator::regenerateFor($leave);
                    $totals['ok']++;
                    $this->info($label.' — regenerated #'.$document->getKey());
                } catch (Throwable $exception) {
                    $totals['failed']++;
                    $this->error($label.' failed: '.$exception->getMessage());
                }
            }

            return true;
        });
    }

    /**
     * @param  array{ok: int, skipped: int, failed: int}  $totals
     */
    protected function regenerateLeaveIsakymas(bool $dryRun, ?int $limit, array &$totals): void
    {
        $query = LeaveRequest::query()
            ->with(['employee', 'leaveRequestType', 'confirmedBy', 'orderDocument.approvedBy'])
            ->whereNotNull('order_document_id')
            ->orderBy('id');

        $processed = 0;
        $query->chunkById(25, function ($chunk) use ($dryRun, $limit, &$totals, &$processed): bool {
            foreach ($chunk as $leave) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }
                $processed++;

                $label = 'leave #'.$leave->getKey().' įsakymas #'.$leave->order_document_id;
                if ($dryRun) {
                    $totals['ok']++;
                    $this->line($label.' — would regenerate');

                    continue;
                }

                try {
                    $document = LeaveRequestOrderDocumentGenerator::regenerateFor($leave);
                    $totals['ok']++;
                    $this->info($label.' — regenerated #'.$document->getKey());
                } catch (Throwable $exception) {
                    $totals['failed']++;
                    $this->error($label.' failed: '.$exception->getMessage());
                }
            }

            return true;
        });
    }

    /**
     * @param  array{ok: int, skipped: int, failed: int}  $totals
     */
    protected function regeneratePayslips(bool $dryRun, ?int $limit, array &$totals): void
    {
        $query = EmployeeMonthlyPayment::query()
            ->with(['employee', 'payslipDocument'])
            ->whereNotNull('payslip_document_id')
            ->orderBy('id');

        $processed = 0;
        $query->chunkById(25, function ($chunk) use ($dryRun, $limit, &$totals, &$processed): bool {
            foreach ($chunk as $payment) {
                if ($limit !== null && $processed >= $limit) {
                    return false;
                }
                $processed++;

                $label = 'payment #'.$payment->getKey().' payslip #'.$payment->payslip_document_id;
                if ($dryRun) {
                    $totals['ok']++;
                    $this->line($label.' — would regenerate');

                    continue;
                }

                try {
                    $result = EmployeePayslipDocumentGenerator::createForPayment(
                        $payment,
                        email: false,
                        forceRegenerate: true,
                    );
                    $totals['ok']++;
                    $this->info($label.' — regenerated #'.$result['document']->getKey());
                } catch (Throwable $exception) {
                    $totals['failed']++;
                    $this->error($label.' failed: '.$exception->getMessage());
                }
            }

            return true;
        });
    }

    /**
     * @return array{bonus: bool, shareholder: bool, leave-prasymas: bool, leave-isakymas: bool, payslip: bool}
     */
    protected function parseOnly(string $raw): array
    {
        $all = [
            'bonus' => true,
            'shareholder' => true,
            'leave-prasymas' => true,
            'leave-isakymas' => true,
            'payslip' => true,
        ];

        $raw = trim($raw);
        if ($raw === '') {
            return $all;
        }

        $selected = collect(explode(',', $raw))
            ->map(fn (string $v): string => strtolower(trim($v)))
            ->filter()
            ->all();

        foreach (array_keys($all) as $key) {
            $all[$key] = in_array($key, $selected, true);
        }

        if (! in_array(true, $all, true)) {
            $this->warn('Unknown --only values; regenerating all categories.');

            return [
                'bonus' => true,
                'shareholder' => true,
                'leave-prasymas' => true,
                'leave-isakymas' => true,
                'payslip' => true,
            ];
        }

        return $all;
    }
}
