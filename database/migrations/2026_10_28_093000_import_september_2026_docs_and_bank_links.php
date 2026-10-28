<?php

use App\Models\BankStatementTransaction;
use App\Models\BusinessTripRequest;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Invoice;
use App\Models\User;
use App\Services\BankStatements\BankStatementBusinessTripMatcher;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Services\BusinessTripDocumentService;
use App\Services\ContactMerger;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Apply 2026-10-28 document uploads + bank/trip links.
 * PDFs are already on SharePoint; this migration upserts DB records and links.
 * Skipped in testing.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (app()->environment('testing')) {
            return;
        }

        if (! Schema::hasTable('invoices') || ! Schema::hasTable('bank_statement_transactions')) {
            return;
        }

        $uploaderId = User::query()->orderBy('id')->value('id') ?? 1;

        $this->ensureBalticAirContact();
        $this->upsertBoltInvoice($uploaderId);
        $this->upsertAirBalticInvoice($uploaderId);
        $this->upsertLitidealInvoices($uploaderId);
        $this->upsertBaltLogisticsInvoice($uploaderId);
        $this->attachHannoverTripDocumentAndLinks($uploaderId);
    }

    public function down(): void
    {
        // Data import — intentionally not reversed.
    }

    private function ensureBalticAirContact(): Contact
    {
        $existing = ContactMerger::matchExisting('Baltic Air Logistics Terminal UAB')
            ?? ContactMerger::matchExisting('Baltic Air Logistics Terminal, UAB')
            ?? Contact::query()->where('company_name', 'like', '%Baltic Air Logistics%')->first();

        if ($existing !== null) {
            return $existing;
        }

        return Contact::query()->create([
            'company_name' => 'Baltic Air Logistics Terminal UAB',
            'company_id' => '124430488',
            'company_vat' => 'LT244304811',
            'company_address' => 'Rodūnios kelias 18, 02188 Vilnius, Lietuva',
            'company_country' => 'LT',
            'default_finance_type' => 'expense:'.Invoice::goodsExpenseTypeId(),
        ]);
    }

    private function upsertBoltInvoice(int $uploaderId): void
    {
        $contact = ContactMerger::matchExisting('Bolt Operations OÜ')
            ?? Contact::query()->where('company_name', 'like', '%Bolt Operations%')->first();
        if ($contact === null) {
            return;
        }

        $invoice = $this->upsertInvoice('LT1526-31638', [
            'contact_id' => $contact->id,
            'invoice_date' => '2026-09-30',
            'sum_without_vat' => 60.39,
            'vat' => 1.15,
            'sum_inc_vat' => 61.54,
            'expense_type_id' => $this->expenseTypeId($contact, 7),
            'paid_amount' => 54.90,
            'paid' => false,
            'file_name' => '384-2026-09-30-LT1526-31638-Bolt-Operations.pdf',
            'sharepoint_path' => 'invoices/2026/2026-09/384-2026-09-30-LT1526-31638-Bolt-Operations.pdf',
            'sharepoint_item_id' => '01TGLQMMRH3YKAI6NW45BLHVBCDMF5QQCR',
            'sharepoint_web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/invoices/2026/2026-09/384-2026-09-30-LT1526-31638-Bolt-Operations.pdf',
            'uploaded_by' => $uploaderId,
        ]);

        $tx = $this->findTxByFingerprint('ad17111f04ef0e5a3a9ed4c61f6967d1a8ee2f17a5bb6216c0ebad7be9d1601f')
            ?? $this->findTx('2026-09-25', 54.90, 'BOLT');
        if ($tx !== null) {
            $this->linkInvoiceIfNeeded($tx, $invoice);
        }
    }

    private function upsertAirBalticInvoice(int $uploaderId): void
    {
        $contact = ContactMerger::matchExisting('Air Baltic Corporation A/S')
            ?? Contact::query()->where('company_name', 'like', '%Air Baltic%')->first();
        if ($contact === null) {
            return;
        }

        $invoice = $this->upsertInvoice('2622847779', [
            'contact_id' => $contact->id,
            'invoice_date' => '2026-09-07',
            'sum_without_vat' => 707.92,
            'vat' => 0,
            'sum_inc_vat' => 707.92,
            'expense_type_id' => $this->expenseTypeId($contact, 5),
            'paid_amount' => 707.92,
            'paid' => true,
            'file_name' => '385-2026-09-07-2622847779-Air-Baltic.pdf',
            'sharepoint_path' => 'invoices/2026/2026-09/385-2026-09-07-2622847779-Air-Baltic.pdf',
            'sharepoint_item_id' => '01TGLQMMQGNMC55BZHKBCYNBUZ4J2G5VTQ',
            'sharepoint_web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/invoices/2026/2026-09/385-2026-09-07-2622847779-Air-Baltic.pdf',
            'uploaded_by' => $uploaderId,
        ]);

        $tx = $this->findTxByFingerprint('4b02305cc45ad0b9eac756ccb2366f97c894d94bcd1894769d63246147e00e3b')
            ?? $this->findTx('2026-09-07', 707.92, '2622847779');
        if ($tx !== null) {
            $this->linkInvoiceIfNeeded($tx, $invoice);
        }
    }

    private function upsertLitidealInvoices(int $uploaderId): void
    {
        $contact = ContactMerger::matchExisting('Litideal, UAB')
            ?? Contact::query()->where('company_name', 'like', '%Litideal%')->first();
        if ($contact === null) {
            return;
        }

        $rent = $this->upsertInvoice('2022858', [
            'contact_id' => $contact->id,
            'invoice_date' => '2026-09-21',
            'sum_without_vat' => 625.65,
            'vat' => 0,
            'sum_inc_vat' => 625.65,
            'expense_type_id' => $this->expenseTypeId($contact, 1),
            'paid_amount' => 0,
            'paid' => false,
            'file_name' => '386-2026-09-21-2022858-Litideal-car-rent.pdf',
            'sharepoint_path' => 'invoices/2026/2026-09/386-2026-09-21-2022858-Litideal-car-rent.pdf',
            'sharepoint_item_id' => '01TGLQMMSX2VX377WFUFDLBKSU6TYNUM5N',
            'sharepoint_web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/invoices/2026/2026-09/386-2026-09-21-2022858-Litideal-car-rent.pdf',
            'additional_documents' => [[
                'name' => '386-2026-09-21-additional-A10146252-Litideal-admin.pdf',
                'original_name' => 'A10146252-Litideal-admin.pdf',
                'path' => 'invoices/2026/2026-09/386-2026-09-21-additional-A10146252-Litideal-admin.pdf',
                'item_id' => '01TGLQMMWCUQSWOFDFB5CLILBB2576UUF5',
                'web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/invoices/2026/2026-09/386-2026-09-21-additional-A10146252-Litideal-admin.pdf',
                'uploaded_at' => '2026-10-28T07:17:47+00:00',
            ]],
            'uploaded_by' => $uploaderId,
        ]);

        $admin = Invoice::findByNumber('A10146252');
        if ($admin === null) {
            $admin = $this->upsertInvoice('A10146252', [
                'contact_id' => $contact->id,
                'invoice_date' => '2026-09-21',
                'sum_without_vat' => 28.93,
                'vat' => 6.07,
                'sum_inc_vat' => 35.00,
                'expense_type_id' => $this->expenseTypeId($contact, 5),
                'paid_amount' => 0,
                'paid' => false,
                'file_name' => '339-2026-09-21-Futura Textiles Europe 10146252.pdf',
                'sharepoint_path' => 'invoices/2026/2026-09/339-2026-09-21-Futura Textiles Europe 10146252.pdf',
                'sharepoint_item_id' => '01TGLQMMVVODUNKTG7XFD3Z2HXYBKOV3Q7',
                'sharepoint_web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/invoices/2026/2026-09/339-2026-09-21-Futura%20Textiles%20Europe%2010146252.pdf',
                'uploaded_by' => $uploaderId,
            ]);
        } elseif ($admin->paid && ! $admin->hasBankPaymentLinks()) {
            $admin->forceFill(['paid' => false, 'paid_amount' => 0])->save();
        }

        if ($rent->paid && ! $rent->hasBankPaymentLinks()) {
            $rent->forceFill(['paid' => false, 'paid_amount' => 0])->save();
        }

        $tx = $this->findTxByFingerprint('a5ada62d3bc5bd90be7341eac93367b72fecbc6460d2ad42bcd0324ced49a8a2')
            ?? $this->findTx('2026-09-16', 860.00, 'Litideal');
        if ($tx === null) {
            return;
        }

        $already = (int) $tx->invoice_id === (int) $rent->id
            || $tx->invoices()->where('invoices.id', $rent->id)->exists();
        if (! $already) {
            if ($tx->invoice_id || $tx->invoices()->exists()) {
                BankStatementInvoiceMatcher::unlink($tx);
                $tx->refresh();
            }
            BankStatementInvoiceMatcher::linkMany($tx, collect([$rent->fresh(), $admin->fresh()]));
            $tx->refresh();
        }

        $tx->forceFill([
            'overpayment_refunded' => true,
            'is_refund' => false,
        ])->save();
    }

    private function upsertBaltLogisticsInvoice(int $uploaderId): void
    {
        $contact = $this->ensureBalticAirContact();

        $invoice = $this->upsertInvoice('BALT2608105', [
            'contact_id' => $contact->id,
            'invoice_date' => '2026-08-17',
            'sum_without_vat' => 452.60,
            'vat' => 0,
            'sum_inc_vat' => 452.60,
            'expense_type_id' => $this->expenseTypeId($contact, Invoice::goodsExpenseTypeId()),
            'paid_amount' => 452.60,
            'paid' => true,
            'file_name' => '387-2026-08-17-BALT2608105-Baltic-Air-Logistics.pdf',
            'sharepoint_path' => 'invoices/2026/2026-08/387-2026-08-17-BALT2608105-Baltic-Air-Logistics.pdf',
            'sharepoint_item_id' => '01TGLQMMWNNIWRWI75ONEZ76VADZDOSDQW',
            'sharepoint_web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/invoices/2026/2026-08/387-2026-08-17-BALT2608105-Baltic-Air-Logistics.pdf',
            'uploaded_by' => $uploaderId,
        ]);

        $tx = $this->findTxByFingerprint('d3a562cb1901489aacd8a152855d6fb5814204fa2f30b274af17d4551bed1c79')
            ?? $this->findTx('2026-09-14', 452.60, 'BALT2608105');
        if ($tx !== null) {
            $this->linkInvoiceIfNeeded($tx, $invoice);
        }
    }

    private function attachHannoverTripDocumentAndLinks(int $uploaderId): void
    {
        if (! Schema::hasTable('business_trip_requests')) {
            return;
        }

        $trip = BusinessTripRequest::query()
            ->whereDate('date_from', '2026-09-16')
            ->whereDate('date_to', '2026-09-18')
            ->orderBy('id')
            ->first();
        if ($trip === null) {
            return;
        }

        $type = BusinessTripDocumentService::ensureDocumentTypeExists();
        $spItemId = '01TGLQMMXHXG3EF6JVUVCLCKCKK6BKQVWN';
        $document = Document::query()->where('sharepoint_item_id', $spItemId)->first();

        if ($document === null) {
            $document = Document::query()->create([
                'document_date' => '2026-09-18',
                'name' => 'Komandiruotė — Deividas Arman — 2026-09-16–2026-09-18',
                'document_type_id' => $type->getKey(),
                'file_path' => null,
                'user_uploaded_id' => $uploaderId,
                'flag_approved' => true,
                'user_approved_id' => $uploaderId,
                'approval_date' => now(),
                'sharepoint_path' => 'documents/Komandiruotė/2026/85-2026-09-18-Komandiruotė — Deividas Arman — 2026-09-16–2026-09-18.pdf',
                'sharepoint_item_id' => $spItemId,
                'sharepoint_web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/documents/Komandiruot%C4%97/2026/85-2026-09-18-Komandiruot%C4%97%20%E2%80%94%20Deividas%20Arman%20%E2%80%94%202026-09-16%E2%80%932026-09-18.pdf',
                'sharepoint_files' => [[
                    'name' => '85-2026-09-18-Komandiruotė — Deividas Arman — 2026-09-16–2026-09-18.pdf',
                    'path' => 'documents/Komandiruotė/2026/85-2026-09-18-Komandiruotė — Deividas Arman — 2026-09-16–2026-09-18.pdf',
                    'item_id' => $spItemId,
                    'web_url' => 'https://futuratextiles.sharepoint.com/sites/dvs/Shared%20Documents/documents/Komandiruot%C4%97/2026/85-2026-09-18-Komandiruot%C4%97%20%E2%80%94%20Deividas%20Arman%20%E2%80%94%202026-09-16%E2%80%932026-09-18.pdf',
                ]],
            ]);
        }

        $trip->documents()->syncWithoutDetaching([$document->getKey()]);

        if ((float) ($trip->expenses_amount ?? 0) < 0.01) {
            $trip->forceFill(['expenses_amount' => 671.27])->save();
        }

        $tripLinks = [
            ['fingerprint' => '5b14f605ca1181eed67b69123ff5394e26b34f7d4823b98fa22a4dc5ee1ea713', 'date' => '2026-09-17', 'amount' => 28.00, 'needle' => 'IAA'],
            ['fingerprint' => 'ebc3eb5152f84d9590313651483aebec89e5dab8ea4522bafd3fb3660052edb3', 'date' => '2026-09-17', 'amount' => 200.81, 'needle' => 'Plaza Hotel'],
            ['fingerprint' => '774b06dafa892ecf8891c5122170a5aaf7e668d9e59062599e405f9710836218', 'date' => '2026-09-18', 'amount' => 295.00, 'needle' => 'H und H'],
            ['fingerprint' => '3eb436e32d92c9583485c18688f0437f4746f161e768ee7a20488685c44a8f0c', 'date' => '2026-09-18', 'amount' => 16.00, 'needle' => 'GfV Hannover'],
        ];

        foreach ($tripLinks as $link) {
            $tx = $this->findTxByFingerprint($link['fingerprint'])
                ?? $this->findTx($link['date'], $link['amount'], $link['needle']);
            if ($tx === null) {
                continue;
            }
            if ((int) $tx->business_trip_request_id === (int) $trip->id) {
                continue;
            }
            BankStatementBusinessTripMatcher::link($tx, $trip);
        }

        $trip->unsetRelation('bankStatementTransactions');
        $trip->syncPaymentStatusFromBankLinks();
    }

    /**
     * @param  array<string, mixed>  $attrs
     */
    private function upsertInvoice(string $number, array $attrs): Invoice
    {
        $existing = Invoice::findByNumber($number);
        $payload = array_merge([
            'invoice_number' => $number,
            'currency' => 'EUR',
            'income_type_id' => null,
            'is_own_account' => false,
            'payment_term' => Invoice::DEFAULT_PAYMENT_TERM_DAYS,
            'upload_date' => now()->toDateString(),
            'pdf_path' => '',
            'file_mime' => 'application/pdf',
        ], $attrs);

        if ($existing !== null) {
            // Keep paid totals if already bank-linked; still refresh SharePoint pointers when empty.
            $updates = [
                'sharepoint_path' => $payload['sharepoint_path'] ?? $existing->sharepoint_path,
                'sharepoint_item_id' => $payload['sharepoint_item_id'] ?? $existing->sharepoint_item_id,
                'sharepoint_web_url' => $payload['sharepoint_web_url'] ?? $existing->sharepoint_web_url,
                'file_name' => $payload['file_name'] ?? $existing->file_name,
                'file_mime' => $payload['file_mime'] ?? $existing->file_mime,
            ];
            if (array_key_exists('additional_documents', $payload) && blank($existing->additional_documents)) {
                $updates['additional_documents'] = $payload['additional_documents'];
            }
            if (blank($existing->sharepoint_item_id)) {
                $existing->forceFill($updates)->save();
            } elseif (array_key_exists('additional_documents', $updates)) {
                $existing->forceFill(['additional_documents' => $updates['additional_documents']])->save();
            }

            return $existing->fresh() ?? $existing;
        }

        return Invoice::query()->create($payload);
    }

    private function linkInvoiceIfNeeded(BankStatementTransaction $tx, Invoice $invoice): void
    {
        if ((int) $tx->invoice_id === (int) $invoice->id) {
            return;
        }
        if ($tx->invoices()->where('invoices.id', $invoice->id)->exists()) {
            return;
        }
        if ($tx->invoice_id || $tx->payment_id || $tx->business_trip_request_id || $tx->document_id) {
            return;
        }

        BankStatementInvoiceMatcher::link($tx, $invoice);
    }

    private function findTxByFingerprint(string $fingerprint): ?BankStatementTransaction
    {
        return BankStatementTransaction::query()
            ->where('fingerprint', $fingerprint)
            ->first();
    }

    private function findTx(string $date, float $amount, string $needle): ?BankStatementTransaction
    {
        return BankStatementTransaction::query()
            ->whereDate('booking_date', $date)
            ->where('amount', $amount)
            ->where(function ($q) use ($needle): void {
                $q->where('counterpart_name', 'like', '%'.$needle.'%')
                    ->orWhere('description', 'like', '%'.$needle.'%');
            })
            ->orderBy('id')
            ->first();
    }

    private function expenseTypeId(Contact $contact, int $fallback): int
    {
        $parsed = Invoice::parseFinanceTypeFilterValue($contact->default_finance_type);

        return (int) ($parsed['expense_type_id'] ?? $fallback);
    }
};
