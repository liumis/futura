<?php

namespace Tests\Unit;

use App\Models\Contact;
use App\Models\Invoice;
use App\Models\User;
use App\Services\ContactMerger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContactMergerTest extends TestCase
{
    use RefreshDatabase;

    public function test_merges_truncated_duplicate_into_full_name(): void
    {
        $user = User::factory()->create();

        $full = Contact::query()->create([
            'company_name' => 'Hans Reinke Handelsgesellschaft mbH',
            'company_id' => 'DE123',
            'company_address' => 'Germany',
            'company_email' => 'info@karmman.com',
        ]);
        $short = Contact::query()->create([
            'company_name' => 'Hans Reinke Handelsg',
            'company_id' => 'AUTO-XXXX',
            'company_address' => 'Auto-created from OCR',
        ]);
        $dup = Contact::query()->create([
            'company_name' => 'Hans Reinke Handelsgesellschaft mbH',
            'company_id' => 'AUTO-YYYY',
            'company_address' => 'Auto-created from bank statement',
        ]);

        Invoice::query()->create([
            'contact_id' => $short->id,
            'invoice_number' => 'HR-1',
            'invoice_date' => '2026-04-08',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
            'upload_date' => '2026-04-08',
            'uploaded_by' => $user->id,
        ]);
        Invoice::query()->create([
            'contact_id' => $dup->id,
            'invoice_number' => 'HR-2',
            'invoice_date' => '2026-04-09',
            'sum_without_vat' => 20,
            'vat' => 0,
            'sum_inc_vat' => 20,
            'upload_date' => '2026-04-09',
            'uploaded_by' => $user->id,
        ]);

        $result = ContactMerger::mergeGroup([$full->id, $short->id, $dup->id]);

        $this->assertSame(2, Invoice::query()->where('contact_id', $result['target_id'])->count());
        $this->assertSame(0, Invoice::query()->whereNull('contact_id')->count());
        $this->assertSame(1, Contact::query()->where('company_name', 'like', '%Reinke%')->count());
        $this->assertSame(
            'Hans Reinke Handelsgesellschaft mbH',
            Contact::query()->find($result['target_id'])?->company_name,
        );
        $this->assertSame('info@karmman.com', Contact::query()->find($result['target_id'])?->company_email);
        $this->assertArrayHasKey('bank_counterparts', $result);
    }

    public function test_merge_into_chosen_target_preserves_source_profile_fields(): void
    {
        $user = User::factory()->create();

        $keep = Contact::query()->create([
            'company_name' => 'Acme',
            'company_id' => 'AUTO-1',
            'company_address' => 'Vilnius',
        ]);
        $other = Contact::query()->create([
            'company_name' => 'Acme Ltd',
            'company_id' => '123456789',
            'company_address' => 'Kaunas street 1',
            'company_vat' => 'LT123',
            'company_email' => 'a@acme.test',
            'bank_account' => 'LT121000011101001000',
        ]);

        Invoice::query()->create([
            'contact_id' => $other->id,
            'invoice_number' => 'A-1',
            'invoice_date' => '2026-04-08',
            'sum_without_vat' => 10,
            'vat' => 0,
            'sum_inc_vat' => 10,
            'upload_date' => '2026-04-08',
            'uploaded_by' => $user->id,
        ]);

        $result = ContactMerger::mergeInto((int) $keep->id, [(int) $other->id]);

        $this->assertSame((int) $keep->id, $result['target_id']);
        $this->assertSame(1, $result['invoices']);
        $this->assertNull(Contact::query()->find($other->id));

        $keep->refresh();
        $this->assertSame('Acme Ltd', $keep->company_name);
        $this->assertSame('123456789', $keep->company_id);
        $this->assertSame('LT123', $keep->company_vat);
        $this->assertSame('a@acme.test', $keep->company_email);
        $this->assertSame('LT121000011101001000', $keep->bank_account);
        $this->assertSame(1, Invoice::query()->where('contact_id', $keep->id)->count());
    }

    public function test_match_existing_reuses_truncation(): void
    {
        Contact::query()->create([
            'company_name' => 'Hans Reinke Handelsgesellschaft mbH',
            'company_id' => 'DE123',
            'company_address' => 'Germany',
        ]);

        $matched = ContactMerger::matchExisting('Hans Reinke Handelsg');
        $this->assertNotNull($matched);
        $this->assertSame('Hans Reinke Handelsgesellschaft mbH', $matched->company_name);
    }
}
