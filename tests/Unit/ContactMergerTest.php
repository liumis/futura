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
        $this->assertSame(1, Contact::query()->where('company_name', 'like', '%Reinke%')->count());
        $this->assertSame(
            'Hans Reinke Handelsgesellschaft mbH',
            Contact::query()->find($result['target_id'])?->company_name,
        );
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
