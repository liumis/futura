<?php

namespace App\Services\BankStatements;

use App\Enums\BankStatementDirection;
use App\Models\BankStatementTransaction;
use App\Models\Contact;
use App\Models\Currency;
use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Services\ContactCountryResolver;
use App\Services\ContactMerger;
use App\Services\LithuanianCompanyNameNormalizer;
use App\Services\SharepointInvoiceUploader;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class BankStatementInvoiceCreator
{
    /**
     * @return array<string, mixed>
     */
    public static function prefill(BankStatementTransaction $transaction): array
    {
        $amount = round((float) $transaction->amount, 2);
        $counterpart = trim((string) ($transaction->counterpart_name ?? ''));
        $contact = self::matchContact($counterpart !== '' ? $counterpart : null);
        $isOwnAccount = $transaction->isInternalTransfer();
        $isIncome = ! $isOwnAccount && (
            $transaction->direction === BankStatementDirection::Credit
            || $transaction->direction?->isIncome()
        );

        $defaults = Invoice::parseFinanceTypeFilterValue($contact?->defaultFinanceTypeValue());

        return [
            'pdf_path' => null,
            'contact_id' => $contact?->getKey(),
            'provider_company_name' => $contact?->company_name ?: ($counterpart !== '' ? $counterpart : null),
            'create_contact_if_missing' => $contact === null ? 1 : 0,
            'provider_company_id' => $contact?->company_id,
            'provider_company_vat' => $contact?->company_vat,
            'provider_company_email' => $contact?->company_email,
            'provider_company_phone' => $contact?->company_phone,
            'provider_company_address' => $contact?->company_address,
            'invoice_number' => self::suggestedInvoiceNumber($transaction),
            'invoice_date' => optional($transaction->booking_date)->toDateString() ?? now()->toDateString(),
            'sum_without_vat' => $amount,
            'vat' => 0,
            'sum_inc_vat' => $amount,
            'currency' => Currency::normalize($transaction->currency),
            'payment_term' => Invoice::DEFAULT_PAYMENT_TERM_DAYS,
            'income_type_id' => $isOwnAccount ? null : ($isIncome ? ($defaults['income_type_id'] ?? Invoice::goodsIncomeTypeId()) : null),
            'expense_type_id' => $isOwnAccount ? null : ($isIncome ? null : ($defaults['expense_type_id'] ?? Invoice::goodsExpenseTypeId())),
            'is_own_account' => $isOwnAccount,
            'is_income' => $isIncome,
            'upload_date' => now()->toDateString(),
            'uploaded_by' => auth()->id(),
        ];
    }

    public static function matchContact(?string $name): ?Contact
    {
        return ContactMerger::matchExisting($name);
    }

    public static function suggestedInvoiceNumber(BankStatementTransaction $transaction): ?string
    {
        $description = trim((string) ($transaction->description ?? ''));
        if ($description === '' || mb_strlen($description) > 40) {
            return null;
        }

        return $description;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(BankStatementTransaction $transaction, array $data, int $uploadedBy): Invoice
    {
        if ($transaction->invoice_id || $transaction->payment_id) {
            throw new RuntimeException('This payment is already linked.');
        }

        $invoiceNumber = filled($data['invoice_number'] ?? null) ? trim((string) $data['invoice_number']) : null;
        $existing = Invoice::findByNumber($invoiceNumber);
        if ($existing !== null) {
            throw new RuntimeException('Invoice number '.$existing->displayNumber().' already exists.');
        }

        if (! SharepointSetting::instance()->isReady()) {
            throw new RuntimeException('SharePoint must be enabled and configured. Invoice PDFs are stored only on SharePoint.');
        }

        $storedPath = $data['pdf_path'] ?? null;
        if (is_array($storedPath)) {
            $storedPath = $storedPath[0] ?? null;
        }
        $storedPath = is_string($storedPath) ? $storedPath : '';

        $disk = Storage::disk('public')->exists($storedPath) ? 'public' : 'local';
        if ($storedPath === '' || ! Storage::disk($disk)->exists($storedPath)) {
            throw new RuntimeException('Uploaded invoice file not found.');
        }

        $binary = (string) Storage::disk($disk)->get($storedPath);
        $mime = Storage::disk($disk)->mimeType($storedPath) ?: 'application/pdf';
        $contactId = $this->resolveContactId($data);
        $defaults = Invoice::parseFinanceTypeFilterValue(
            Contact::query()->whereKey($contactId)->value('default_finance_type'),
        );
        $isOwnAccount = filter_var($data['is_own_account'] ?? false, FILTER_VALIDATE_BOOLEAN)
            || $transaction->isInternalTransfer();
        $isIncome = ! $isOwnAccount && filter_var($data['is_income'] ?? false, FILTER_VALIDATE_BOOLEAN);

        try {
            $invoice = Invoice::query()->create([
                'contact_id' => $contactId,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => $data['invoice_date'],
                'sum_without_vat' => round((float) ($data['sum_without_vat'] ?? 0), 2),
                'vat' => round((float) ($data['vat'] ?? 0), 2),
                'sum_inc_vat' => round((float) ($data['sum_inc_vat'] ?? 0), 2),
                'currency' => Currency::normalize(
                    isset($data['currency']) && is_string($data['currency'])
                        ? $data['currency']
                        : $transaction->currency,
                ),
                'income_type_id' => $isOwnAccount ? null : ($isIncome ? ($data['income_type_id'] ?? $defaults['income_type_id']) : null),
                'expense_type_id' => $isOwnAccount ? null : ($isIncome ? null : ($data['expense_type_id'] ?? $defaults['expense_type_id'])),
                'is_own_account' => $isOwnAccount,
                'payment_term' => Invoice::resolvePaymentTerm($data['payment_term'] ?? null),
                'paid_amount' => round((float) ($data['paid_amount'] ?? $data['sum_inc_vat'] ?? 0), 2),
                'paid' => (bool) ($data['paid'] ?? false),
                'upload_date' => $data['upload_date'] ?? now()->toDateString(),
                'uploaded_by' => $uploadedBy,
                'pdf_path' => '',
                'file_name' => basename($storedPath),
                'file_mime' => $mime,
            ]);
        } catch (UniqueConstraintViolationException) {
            $existing = Invoice::findByNumber($invoiceNumber);
            throw new RuntimeException(
                'Invoice number '.($existing?->displayNumber() ?? $invoiceNumber).' already exists.',
            );
        }

        SharepointInvoiceUploader::ingestBinary($invoice, $binary, basename($storedPath), $storedPath);

        Contact::query()->find($contactId)?->rememberDefaultCategoryFromInvoice($invoice);

        $transaction->update([
            'invoice_id' => $invoice->getKey(),
            'payment_id' => null,
        ]);

        return $invoice->fresh() ?? $invoice;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveContactId(array $data): int
    {
        $contactId = $data['contact_id'] ?? null;
        if (is_numeric($contactId)) {
            return (int) $contactId;
        }

        if (! $this->shouldCreateMissingContact($data)) {
            throw new RuntimeException('Company is required.');
        }

        $name = LithuanianCompanyNameNormalizer::normalize(trim((string) ($data['provider_company_name'] ?? '')));
        if ($name === '') {
            throw new RuntimeException('Please select a company or enter a provider name to create a contact.');
        }

        $existing = self::matchContact($name);
        if ($existing !== null) {
            $existingNorm = ContactMerger::normalizeName((string) $existing->company_name);
            $newNorm = ContactMerger::normalizeName($name);
            // Prefer the longer real name on the kept contact.
            if (
                $newNorm !== ''
                && (str_starts_with($newNorm, $existingNorm) || strlen($newNorm) > strlen($existingNorm))
                && mb_strlen($name) > mb_strlen((string) $existing->company_name)
            ) {
                $existing->forceFill(['company_name' => $name])->save();
            }

            return (int) $existing->getKey();
        }

        $contact = Contact::query()->create([
            'company_name' => $name,
            'company_id' => (string) ($data['provider_company_id'] ?? ('AUTO-'.Str::upper(Str::random(8)))),
            'company_vat' => $data['provider_company_vat'] ?? null,
            'company_address' => (string) ($data['provider_company_address'] ?? 'Auto-created from bank statement'),
            'company_email' => $data['provider_company_email'] ?? null,
            'company_phone' => $data['provider_company_phone'] ?? null,
            'company_country' => ContactCountryResolver::infer(
                name: $name,
                companyId: (string) ($data['provider_company_id'] ?? ''),
                vat: isset($data['provider_company_vat']) ? (string) $data['provider_company_vat'] : null,
                email: isset($data['provider_company_email']) ? (string) $data['provider_company_email'] : null,
                address: isset($data['provider_company_address']) ? (string) $data['provider_company_address'] : null,
            ),
        ]);

        return (int) $contact->getKey();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shouldCreateMissingContact(array $data): bool
    {
        if (! array_key_exists('create_contact_if_missing', $data) || $data['create_contact_if_missing'] === null) {
            return true;
        }

        return (int) $data['create_contact_if_missing'] === 1;
    }
}
