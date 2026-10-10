<?php

namespace App\Filament\Admin\Concerns;

use App\Models\CompanySetting;
use App\Models\Contact;
use App\Models\Invoice;
use App\Services\BankStatements\BankStatementInvoiceCreator;
use App\Services\InvoiceOcrService;
use App\Services\LithuanianCompanyNameNormalizer;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

trait PrefillsInvoiceFromOcr
{
    /**
     * @var list<string>
     */
    protected function invoiceOcrFillableFields(): array
    {
        return [
            'invoice_number',
            'provider_company_name',
            'provider_company_id',
            'provider_company_vat',
            'provider_company_email',
            'invoice_date',
            'sum_without_vat',
            'vat',
            'sum_inc_vat',
            'currency',
        ];
    }

    protected function applyOcrPrefill(mixed $state, callable $set): void
    {
        if (blank($state) || $state === [] || $state === [null]) {
            return;
        }

        $path = InvoiceOcrService::resolveUploadedPath($state);
        if ($path === null || ! is_file($path) || (int) filesize($path) < 32) {
            Log::warning('Invoice OCR could not resolve upload path', [
                'state_type' => get_debug_type($state),
                'resolved' => $path,
            ]);

            Notification::make()
                ->title('Could not read the uploaded file yet')
                ->body('Wait until the green “Upload complete” appears, then remove the file and attach it again.')
                ->warning()
                ->send();

            return;
        }

        try {
            $ocr = app(InvoiceOcrService::class)->extract($path);
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not read the invoice file')
                ->body($exception->getMessage())
                ->warning()
                ->send();

            return;
        }

        if ($ocr === []) {
            Notification::make()
                ->title('No invoice data detected')
                ->body($this->missingInvoiceOcrHelp())
                ->warning()
                ->send();

            return;
        }

        $updates = [];
        foreach ($this->invoiceOcrFillableFields() as $field) {
            if (array_key_exists($field, $ocr) && filled($ocr[$field])) {
                $updates[$field] = $ocr[$field];
            }
        }

        $matchedContact = $this->matchContactFromOcr($ocr);
        if ($matchedContact !== null) {
            $updates['contact_id'] = $matchedContact->getKey();
            $updates['private_provider'] = (bool) $matchedContact->private_provider;
            $updates['provider_company_name'] = $matchedContact->company_name ?: ($ocr['provider_company_name'] ?? null);
            $updates['provider_company_id'] = $matchedContact->company_id;
            $updates['provider_company_address'] = $matchedContact->company_address;
            $updates['provider_company_phone'] = $matchedContact->company_phone;
            $updates['provider_company_email'] = $matchedContact->company_email ?: ($ocr['provider_company_email'] ?? null);
            $updates['provider_company_vat'] = $matchedContact->company_vat ?: ($ocr['provider_company_vat'] ?? null);
            $updates['create_contact_if_missing'] = 0;
            if ($matchedContact->defaultFinanceTypeValue() !== null) {
                $updates['finance_type'] = $matchedContact->defaultFinanceTypeValue();
            }
        } elseif (filled($ocr['provider_company_name'] ?? null)) {
            $updates['contact_id'] = null;
            $updates['private_provider'] = false;
            $updates['create_contact_if_missing'] = 1;
        }

        if (
            ! isset($updates['finance_type'])
            && (
                ($ocr['is_own_sales_invoice'] ?? false) === true
                || str_starts_with(strtoupper((string) ($updates['invoice_number'] ?? '')), 'FTE')
            )
        ) {
            $incomeId = Invoice::goodsIncomeTypeId();
            if ($incomeId !== null) {
                $updates['finance_type'] = 'income:'.$incomeId;
            }
        }

        foreach ($updates as $field => $value) {
            if (is_string($value)) {
                $updates[$field] = InvoiceOcrService::sanitizeUtf8($value);
            }
        }

        foreach ($updates as $field => $value) {
            $set($field, $value);
        }

        $this->persistOcrPrefillToLivewire($updates);

        Notification::make()
            ->title('Invoice fields prefilled from file')
            ->body($this->ocrPrefillSummary($updates))
            ->success()
            ->send();
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    protected function persistOcrPrefillToLivewire(array $updates): void {}

    /**
     * @param  array<string, mixed>  $updates
     */
    protected function ocrPrefillSummary(array $updates): string
    {
        $labels = [
            'provider_company_name' => 'Provider',
            'provider_company_id' => 'Company ID',
            'provider_company_vat' => 'VAT',
            'invoice_number' => 'Invoice no',
            'invoice_date' => 'Date',
            'sum_without_vat' => 'Net',
            'vat' => 'VAT amount',
            'sum_inc_vat' => 'Total',
            'currency' => 'Currency',
        ];

        $parts = [];
        foreach ($labels as $field => $label) {
            if (! array_key_exists($field, $updates) || ! filled($updates[$field])) {
                continue;
            }

            $parts[] = $label.': '.$updates[$field];
        }

        if ($parts === []) {
            return 'Review the values before uploading.';
        }

        return implode(' · ', $parts).'. Review before uploading.';
    }

    /**
     * @param  array<string, mixed>  $ocr
     */
    protected function matchContactFromOcr(array $ocr): ?Contact
    {
        $vat = strtoupper(preg_replace('/\s+/', '', (string) ($ocr['provider_company_vat'] ?? '')) ?? '');
        if ($vat !== '') {
            $contact = Contact::query()
                ->whereRaw("REPLACE(UPPER(COALESCE(company_vat, '')), ' ', '') = ?", [$vat])
                ->first();

            if ($contact instanceof Contact && ! $this->isOwnCompanyContact($contact)) {
                return $contact;
            }
        }

        $companyId = preg_replace('/\D+/', '', (string) ($ocr['provider_company_id'] ?? '')) ?? '';
        if (strlen($companyId) >= 7) {
            $contact = Contact::query()->where('company_id', $companyId)->first();
            if ($contact instanceof Contact && ! $this->isOwnCompanyContact($contact)) {
                return $contact;
            }
        }

        $name = trim((string) ($ocr['provider_company_name'] ?? ''));
        if ($name === '') {
            return null;
        }

        $contact = BankStatementInvoiceCreator::matchContact($name)
            ?? Contact::query()
                ->whereRaw('LOWER(company_name) = ?', [Str::lower($name)])
                ->first();

        return $contact instanceof Contact && ! $this->isOwnCompanyContact($contact)
            ? $contact
            : null;
    }

    protected function isOwnCompanyContact(Contact $contact): bool
    {
        $own = CompanySetting::instance();

        if (filled($own->company_id) && (string) $contact->company_id === (string) $own->company_id) {
            return true;
        }

        $ownVat = strtoupper(preg_replace('/\s+/', '', (string) ($own->company_vat ?? '')) ?? '');
        $contactVat = strtoupper(preg_replace('/\s+/', '', (string) ($contact->company_vat ?? '')) ?? '');
        if ($ownVat !== '' && $ownVat === $contactVat) {
            return true;
        }

        $ownName = Str::lower(trim((string) ($own->company_name ?? '')));

        return $ownName !== '' && $ownName === Str::lower(trim((string) ($contact->company_name ?? '')));
    }

    private function missingInvoiceOcrHelp(): string
    {
        $ocr = app(InvoiceOcrService::class);
        if ($ocr->hasRemoteOcr() || $ocr->hasAiMapping()) {
            return 'Fill the fields manually. The file did not contain readable invoice text.';
        }

        return 'Fill the fields manually. Scanned files need OCR_SPACE_API_KEY; unknown layouts can use INVOICE_AI_API_KEY in .env.';
    }
}
