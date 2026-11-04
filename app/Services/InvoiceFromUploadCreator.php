<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Support\ExtractedTextNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class InvoiceFromUploadCreator
{
    public function __construct(
        private readonly InvoiceOcrService $ocr,
    ) {}

    /**
     * @return array{
     *     status: 'created'|'draft'|'duplicate'|'failed',
     *     invoice: ?Invoice,
     *     message: string
     * }
     */
    public function createFromStoredPath(
        string $relativePath,
        string $disk = 'public',
        ?int $uploadedBy = null,
        ?string $originalFilename = null,
    ): array {
        $filename = $originalFilename ?: basename($relativePath);

        if (! SharepointSetting::instance()->isReady()) {
            return [
                'status' => 'failed',
                'invoice' => null,
                'message' => $filename.': SharePoint is not configured.',
            ];
        }

        if ($relativePath === '' || ! Storage::disk($disk)->exists($relativePath)) {
            return [
                'status' => 'failed',
                'invoice' => null,
                'message' => $filename.': uploaded file not found.',
            ];
        }

        $absolutePath = Storage::disk($disk)->path($relativePath);
        $binary = (string) Storage::disk($disk)->get($relativePath);
        $mime = Storage::disk($disk)->mimeType($relativePath) ?: 'application/octet-stream';

        try {
            $ocr = $this->ocr->extract($absolutePath);
        } catch (Throwable $exception) {
            $ocr = [];
            $ocrError = $exception->getMessage();
        }

        $isComplete = $ocr !== [] && $this->ocr->isCompleteParse($ocr);
        $invoiceNumber = filled($ocr['invoice_number'] ?? null) ? trim((string) $ocr['invoice_number']) : null;

        if ($invoiceNumber !== null) {
            $existing = Invoice::findByNumber($invoiceNumber);
            if ($existing !== null) {
                return [
                    'status' => 'duplicate',
                    'invoice' => $existing,
                    'message' => $filename.': invoice '.$existing->displayNumber().' already exists.',
                ];
            }
        }

        $matched = $this->matchContact($ocr);
        $providerName = LithuanianCompanyNameNormalizer::normalize(trim((string) ($ocr['provider_company_name'] ?? '')));

        if (
            $matched !== null
            && $providerName !== ''
            && (
                ExtractedTextNormalizer::looksCorrupted((string) $matched->company_name)
                || trim((string) $matched->company_name) === ''
            )
        ) {
            $matched->forceFill(['company_name' => $providerName])->save();
        }

        $contactId = $matched?->getKey();

        if ($contactId === null && $providerName !== '') {
            try {
                $contactId = $this->createOrReuseContact($ocr, $providerName);
            } catch (Throwable $exception) {
                if ($isComplete) {
                    return [
                        'status' => 'failed',
                        'invoice' => null,
                        'message' => $filename.': could not create contact — '.$exception->getMessage(),
                    ];
                }
            }
        }

        if ($isComplete && $contactId === null) {
            return [
                'status' => 'draft',
                'invoice' => $this->storeInvoice(
                    ocr: $ocr,
                    contactId: null,
                    isDraft: true,
                    binary: $binary,
                    mime: $mime,
                    relativePath: $relativePath,
                    filename: $filename,
                    uploadedBy: $uploadedBy,
                    note: 'OCR looked complete but no provider/contact could be resolved.',
                ),
                'message' => $filename.': saved as draft (provider missing).',
            ];
        }

        $isDraft = ! $isComplete || $contactId === null;
        if (isset($ocrError) && $ocr === []) {
            $isDraft = true;
        }

        try {
            $invoice = $this->storeInvoice(
                ocr: $ocr,
                contactId: $contactId,
                isDraft: $isDraft,
                binary: $binary,
                mime: $mime,
                relativePath: $relativePath,
                filename: $filename,
                uploadedBy: $uploadedBy,
                note: isset($ocrError) ? 'OCR failed: '.$ocrError : ($isDraft ? 'Incomplete OCR — finish fields and save.' : null),
            );
        } catch (UniqueConstraintViolationException) {
            $existing = Invoice::findByNumber($invoiceNumber);

            return [
                'status' => 'duplicate',
                'invoice' => $existing,
                'message' => $filename.': invoice number already exists.',
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'failed',
                'invoice' => null,
                'message' => $filename.': '.$exception->getMessage(),
            ];
        }

        if ($contactId !== null && ! $isDraft) {
            Contact::query()->find($contactId)?->rememberDefaultCategoryFromInvoice($invoice);
        }

        $dateHint = filled($invoice->invoice_date)
            ? ' Invoice date '.$invoice->invoice_date->format('Y-m-d').' — open that month or All months on Invoices.'
            : '';

        return [
            'status' => $isDraft ? 'draft' : 'created',
            'invoice' => $invoice,
            'message' => ($isDraft
                ? $filename.': saved as draft (incomplete scan).'
                : $filename.': invoice created.')
                .$dateHint,
        ];
    }

    /**
     * @param  array<string, mixed>  $ocr
     */
    private function matchContact(array $ocr): ?Contact
    {
        $vat = strtoupper(preg_replace('/\s+/', '', (string) ($ocr['provider_company_vat'] ?? '')) ?? '');
        if ($vat !== '') {
            $contact = Contact::query()
                ->whereRaw("REPLACE(UPPER(COALESCE(company_vat, '')), ' ', '') = ?", [$vat])
                ->first();
            if ($contact instanceof Contact) {
                return $contact;
            }
        }

        $companyId = preg_replace('/\D+/', '', (string) ($ocr['provider_company_id'] ?? '')) ?? '';
        if (strlen($companyId) >= 7) {
            $contact = Contact::query()->where('company_id', $companyId)->first();
            if ($contact instanceof Contact) {
                return $contact;
            }
        }

        $name = trim((string) ($ocr['provider_company_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        return ContactMerger::matchExisting($name)
            ?? Contact::query()->whereRaw('LOWER(company_name) = ?', [Str::lower($name)])->first();
    }

    /**
     * @param  array<string, mixed>  $ocr
     */
    private function createOrReuseContact(array $ocr, string $name): int
    {
        $existing = ContactMerger::matchExisting($name);
        if ($existing !== null) {
            return (int) $existing->getKey();
        }

        $contact = Contact::query()->create([
            'company_name' => $name,
            'company_id' => (string) ($ocr['provider_company_id'] ?? ('AUTO-'.Str::upper(Str::random(8)))),
            'company_vat' => $ocr['provider_company_vat'] ?? null,
            'company_address' => (string) ($ocr['provider_company_address'] ?? 'Auto-created from OCR'),
            'company_email' => $ocr['provider_company_email'] ?? null,
            'company_phone' => $ocr['provider_company_phone'] ?? null,
            'private_provider' => false,
            'company_country' => ContactCountryResolver::infer(
                name: $name,
                companyId: (string) ($ocr['provider_company_id'] ?? ''),
                vat: isset($ocr['provider_company_vat']) ? (string) $ocr['provider_company_vat'] : null,
                email: isset($ocr['provider_company_email']) ? (string) $ocr['provider_company_email'] : null,
                address: isset($ocr['provider_company_address']) ? (string) $ocr['provider_company_address'] : null,
            ),
        ]);

        return (int) $contact->getKey();
    }

    /**
     * @param  array<string, mixed>  $ocr
     */
    private function storeInvoice(
        array $ocr,
        ?int $contactId,
        bool $isDraft,
        string $binary,
        string $mime,
        string $relativePath,
        string $filename,
        ?int $uploadedBy,
        ?string $note,
    ): Invoice {
        $sumInc = isset($ocr['sum_inc_vat']) ? round((float) $ocr['sum_inc_vat'], 2) : 0.0;
        $sumNet = isset($ocr['sum_without_vat']) ? round((float) $ocr['sum_without_vat'], 2) : 0.0;
        $vat = isset($ocr['vat']) ? round((float) $ocr['vat'], 2) : round(max(0, $sumInc - $sumNet), 2);
        if ($sumNet <= 0 && $sumInc > 0) {
            $sumNet = round(max(0, $sumInc - $vat), 2);
        }
        if ($sumInc <= 0 && $sumNet > 0) {
            $sumInc = round($sumNet + $vat, 2);
        }

        $financeType = null;
        if ($contactId !== null) {
            $financeType = Contact::query()->whereKey($contactId)->value('default_finance_type');
        }
        if (
            ! filled($financeType)
            && (
                ($ocr['is_own_sales_invoice'] ?? false) === true
                || str_starts_with(strtoupper((string) ($ocr['invoice_number'] ?? '')), 'FTE')
            )
        ) {
            $incomeId = Invoice::goodsIncomeTypeId();
            $financeType = $incomeId !== null ? 'income:'.$incomeId : null;
        }
        $category = Invoice::parseFinanceTypeFilterValue(is_string($financeType) ? $financeType : null);

        $invoiceDate = filled($ocr['invoice_date'] ?? null)
            ? (string) $ocr['invoice_date']
            : ($isDraft ? null : now()->toDateString());

        $commentParts = array_filter([
            $note,
            $isDraft && filled($ocr['provider_company_name'] ?? null) && $contactId === null
                ? 'OCR provider: '.trim((string) $ocr['provider_company_name'])
                : null,
        ]);

        $userId = $uploadedBy ?? User::query()->value('id');
        if ($userId === null) {
            throw new RuntimeException('No user available for uploaded_by.');
        }

        $invoice = Invoice::query()->create([
            'contact_id' => $contactId,
            'invoice_number' => filled($ocr['invoice_number'] ?? null) ? trim((string) $ocr['invoice_number']) : null,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $sumNet,
            'vat' => $vat,
            'sum_inc_vat' => $sumInc,
            'currency' => Currency::normalize(isset($ocr['currency']) && is_string($ocr['currency']) ? $ocr['currency'] : null),
            'payment_term' => Invoice::DEFAULT_PAYMENT_TERM_DAYS,
            'paid_amount' => 0,
            'paid' => false,
            'private_provider' => false,
            'comment' => $commentParts !== [] ? implode(' ', $commentParts) : null,
            'is_draft' => $isDraft,
            'income_type_id' => $category['income_type_id'],
            'expense_type_id' => $category['expense_type_id'],
            'is_own_account' => $category['is_own_account'],
            'upload_date' => now()->toDateString(),
            'uploaded_by' => $userId,
            'pdf_path' => '',
            'file_name' => $filename,
            'file_mime' => $mime,
        ]);

        try {
            SharepointInvoiceUploader::ingestBinary($invoice, $binary, $filename, $relativePath);
        } catch (Throwable $exception) {
            $invoice->delete();

            throw $exception;
        }

        return $invoice->fresh() ?? $invoice;
    }
}
