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
            } catch (Throwable) {
                // Keep as draft without contact — user can pick/create on review.
            }
        }

        // Bulk / OCR uploads always stay draft until someone opens and confirms.
        $isDraft = true;
        $note = match (true) {
            isset($ocrError) && $ocr === [] => 'OCR failed: '.$ocrError,
            $contactId === null && $providerName !== '' => 'Review needed — provider not matched to a contact.',
            $contactId === null => 'Review needed — provider/contact missing.',
            ! $isComplete => 'Review needed — OCR/AI fields incomplete.',
            default => 'OCR/AI prefilled — review and confirm to publish.',
        };

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
                note: $note,
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

        $dateHint = filled($invoice->invoice_date)
            ? ' Invoice date '.$invoice->invoice_date->format('Y-m-d').'.'
            : '';

        return [
            'status' => 'draft',
            'invoice' => $invoice,
            'message' => $filename.': saved as draft — open, check, then Confirm & publish.'.$dateHint,
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
        if ($this->looksLikeBogusProviderName($name)) {
            throw new RuntimeException('OCR provider name looks invalid: '.$name);
        }

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

    private function looksLikeBogusProviderName(string $name): bool
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) < 3) {
            return true;
        }

        $lower = Str::lower($name);

        if (preg_match('/^\d{1,2}[.\-\/]\d{1,2}[.\-\/](?:\d{2}|\d{4})$/', $name) === 1) {
            return true;
        }

        if (preg_match('/^\d{4}[.\-\/]\d{1,2}[.\-\/]\d{1,2}$/', $name) === 1) {
            return true;
        }

        if (preg_match('/^(january|february|march|april|may|june|july|august|september|october|november|december)\b/i', $name) === 1) {
            return true;
        }

        return Str::contains($lower, [
            'payment term',
            'bill to',
            'invoice date',
            'sąskaitos data',
            'saskaitos data',
        ]);
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

        $contact = $contactId !== null ? Contact::query()->find($contactId) : null;

        $invoice = Invoice::query()->create([
            'contact_id' => $contactId,
            'invoice_number' => filled($ocr['invoice_number'] ?? null) ? trim((string) $ocr['invoice_number']) : null,
            'invoice_date' => $invoiceDate,
            'sum_without_vat' => $sumNet,
            'vat' => $vat,
            'sum_inc_vat' => $sumInc,
            'currency' => Currency::normalize(isset($ocr['currency']) && is_string($ocr['currency']) ? $ocr['currency'] : null),
            'payment_term' => Invoice::paymentTermFromContact($contact),
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
