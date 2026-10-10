<?php

namespace App\Services;

use App\Models\CompanySetting;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\EmployeeMonthlyPayment;
use App\Models\SharepointSetting;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

final class EmployeePayslipDocumentGenerator
{
    public const DOCUMENT_TYPE_NAME = 'Atsiskaitymo lapelis';

    public static function ensureDocumentTypeExists(): DocumentType
    {
        return DocumentType::query()->firstOrCreate(
            ['name' => self::DOCUMENT_TYPE_NAME],
            ['group_by_year' => true],
        );
    }

    /**
     * Generate payslip PDF, store in SharePoint/Documents, optionally email employee, link on payment.
     *
     * @return array{document: Document, emailed: bool, email_message: string|null}
     */
    public static function createForPayment(
        EmployeeMonthlyPayment $payment,
        ?User $actor = null,
        bool $email = true,
        bool $allowLocalStorage = false,
        bool $forceRegenerate = false,
    ): array {
        $payment->loadMissing(['employee', 'payslipDocument']);

        if (! $forceRegenerate && filled($payment->payslip_document_id) && $payment->payslipDocument !== null) {
            return [
                'document' => $payment->payslipDocument,
                'emailed' => false,
                'email_message' => 'Payslip already exists.',
            ];
        }

        $employee = $payment->employee;
        if ($employee === null) {
            throw new RuntimeException('Payment has no employee.');
        }

        $actor ??= auth()->user();
        $binary = EmployeePayslipPdfGenerator::generate($payment);
        $type = self::ensureDocumentTypeExists();
        $employeeName = $employee->fullName() ?: ('#'.$employee->getKey());
        $period = $payment->payment_date?->format('Y-m') ?? now()->format('Y-m');
        $documentName = sprintf('Atsiskaitymo lapelis — %s — %s', $employeeName, $period);
        $fileName = sprintf(
            'payslip-%s-%s.pdf',
            Str::slug($employeeName, '-') ?: 'employee',
            $payment->getKey(),
        );
        $hash = hash('sha256', $binary);

        $existing = $payment->payslipDocument;
        if ($forceRegenerate && $existing instanceof Document) {
            $existing->forceFill([
                'document_date' => $payment->payment_date ?? $existing->document_date ?? now(),
                'name' => $documentName,
                'document_type_id' => $type->getKey(),
                'content_hash' => $hash,
                'pdf_hash' => $hash,
            ])->save();

            self::persistPdf($existing->fresh(['documentType']), $binary, $fileName, $allowLocalStorage);

            ActivityLogger::logReportGenerated('Employee payslip PDF', 'pdf', $payment, [
                'document_id' => $existing->getKey(),
                'employee_id' => $employee->getKey(),
                'mode' => 'regenerated',
            ]);

            return [
                'document' => $existing->fresh(['documentType']),
                'emailed' => false,
                'email_message' => $email ? 'Payslip regenerated (email not re-sent).' : null,
            ];
        }

        $document = Document::query()->create([
            'document_date' => $payment->payment_date ?? now(),
            'name' => $documentName,
            'document_type_id' => $type->getKey(),
            'file_path' => null,
            'user_uploaded_id' => $actor instanceof User ? $actor->getKey() : null,
            'flag_approved' => true,
            'user_approved_id' => $actor instanceof User ? $actor->getKey() : null,
            'approval_date' => now(),
            'content_hash' => $hash,
            'pdf_hash' => $hash,
        ]);

        self::persistPdf($document, $binary, $fileName, $allowLocalStorage);

        $document = $document->fresh(['documentType']);

        $payment->forceFill([
            'payslip_document_id' => $document->getKey(),
        ])->saveQuietly();

        ActivityLogger::logReportGenerated('Employee payslip PDF', 'pdf', $payment, [
            'document_id' => $document->getKey(),
            'employee_id' => $employee->getKey(),
            'mode' => 'created',
        ]);

        if (! $email) {
            return [
                'document' => $document,
                'emailed' => false,
                'email_message' => null,
            ];
        }

        [$emailed, $emailMessage] = self::emailEmployee($payment, $document, $binary, $fileName);

        return [
            'document' => $document,
            'emailed' => $emailed,
            'email_message' => $emailMessage,
        ];
    }

    protected static function persistPdf(
        Document $document,
        string $binary,
        string $fileName,
        bool $allowLocalStorage,
    ): void {
        if (SharepointSetting::instance()->isReady()) {
            try {
                DocumentBinaryStore::storeBinary(
                    $document->fresh(['documentType']),
                    $binary,
                    $fileName,
                );

                return;
            } catch (Throwable $exception) {
                Log::warning('Payslip SharePoint upload failed', [
                    'document_id' => $document->getKey(),
                    'message' => $exception->getMessage(),
                ]);

                if (! $allowLocalStorage) {
                    throw new RuntimeException(
                        'Payslip PDF storage failed: '.$exception->getMessage(),
                        previous: $exception,
                    );
                }
            }
        } elseif (! $allowLocalStorage) {
            throw new RuntimeException('SharePoint must be enabled and configured. Documents are stored only on SharePoint.');
        }

        $path = 'employee-payslips/'.$fileName;
        Storage::disk('public')->put($path, $binary);
        $document->forceFill([
            'file_path' => $path,
        ])->save();
    }

    /**
     * @return array{0: bool, 1: string|null}
     */
    protected static function emailEmployee(
        EmployeeMonthlyPayment $payment,
        Document $document,
        string $binary,
        string $fileName,
    ): array {
        $employee = $payment->employee;
        $email = trim((string) ($employee?->email ?? ''));

        if ($email === '') {
            return [false, 'Employee has no email address.'];
        }

        $employeeName = $employee->fullName() ?: 'employee';
        $period = $payment->payment_date?->format('Y-m') ?? '';
        $subject = trim('Atsiskaitymo lapelis'.($period !== '' ? ' '.$period : '').' — '.$employeeName);
        $companyName = trim((string) (CompanySetting::instance()->company_name ?? ''));
        $body = implode("\n", [
            'Sveiki'.($employeeName !== 'employee' ? ', '.$employeeName : '').',',
            '',
            'Siunčiame Jūsų atsiskaitymo lapelį'.($period !== '' ? ' už laikotarpį '.$period : '').'.',
            '',
            'Pagarbiai,',
            $companyName !== '' ? $companyName : 'Darbdavys',
        ]);

        $fromAddress = (string) config('mail.from.address');
        $fromName = (string) (config('mail.from.name') ?: $fromAddress);

        if (EmailTestMode::isEnabled()) {
            EmailLogWriter::logManual(
                to: $email,
                subject: $subject,
                body: $body,
                from: sprintf('%s <%s>', $fromName, $fromAddress),
                bodyAppendix: EmailTestMode::blockedMessage().' (payslip document #'.$document->getKey().')',
            );

            return [false, EmailTestMode::blockedMessage()];
        }

        try {
            $sentMessage = Mail::raw($body, function ($message) use ($email, $subject, $fromAddress, $fromName, $binary, $fileName): void {
                $message->getHeaders()->addTextHeader('X-Futura-Email-Log', 'handled');
                $message->to($email)->subject($subject);
                $message->from($fromAddress, $fromName);
                $message->attachData($binary, $fileName, ['mime' => 'application/pdf']);
            });

            if ($sentMessage !== null) {
                EmailLogWriter::logFromSentMessage(
                    $sentMessage,
                    auth()->id(),
                    config('mail.default'),
                    'Payslip document #'.$document->getKey(),
                );
            }

            return [true, null];
        } catch (Throwable $e) {
            report($e);

            return [false, 'Payslip email failed: '.$e->getMessage()];
        }
    }
}
