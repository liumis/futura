<?php

namespace Tests\Feature;

use App\Enums\ActivityLogEvent;
use App\Models\ActivityLog;
use App\Models\BankStatementImport;
use App\Models\Contact;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ActivityLoggingCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin', 'web');
        $this->admin = User::factory()->create(['email' => 'activity-admin@example.com']);
        $this->admin->assignRole('admin');
        $this->actingAs($this->admin);
    }

    public function test_bank_statement_import_create_is_logged(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'seb.xml',
            'transaction_count' => 3,
            'imported_count' => 3,
            'skipped_count' => 0,
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'event_key' => 'bank_statement_import.created',
            'subject_type' => $import->getMorphClass(),
            'subject_id' => $import->getKey(),
            'user_id' => $this->admin->getKey(),
        ]);
    }

    public function test_file_uploaded_and_opened_helpers_are_logged(): void
    {
        $import = BankStatementImport::query()->create([
            'original_filename' => 'swedbank.xlsx',
            'transaction_count' => 1,
            'imported_count' => 1,
            'skipped_count' => 0,
            'sharepoint_web_url' => 'https://example.test/bank.xlsx',
            'sharepoint_path' => 'bank-statements/2026/2026-09/1-file.xlsx',
        ]);

        ActivityLog::query()->where('event_key', 'bank_statement_import.created')->delete();

        ActivityLogger::logFileUploaded('Bank statement', 'xlsx', $import, [
            'file_name' => 'swedbank.xlsx',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'event_key' => ActivityLogEvent::FileUploaded->value,
            'user_id' => $this->admin->getKey(),
            'subject_id' => $import->getKey(),
        ]);

        $this->get(route('bank-statement-imports.file', $import))
            ->assertRedirect('https://example.test/bank.xlsx');

        $this->assertDatabaseHas('activity_logs', [
            'event_key' => ActivityLogEvent::FileOpened->value,
            'user_id' => $this->admin->getKey(),
            'subject_id' => $import->getKey(),
        ]);
    }

    public function test_contact_create_and_update_are_logged(): void
    {
        $contact = Contact::query()->create([
            'company_name' => 'Logged Co',
            'company_id' => '123',
            'company_address' => 'Vilnius',
        ]);

        $this->assertDatabaseHas('activity_logs', [
            'event_key' => 'contact.created',
            'subject_id' => $contact->getKey(),
        ]);

        $contact->update(['company_name' => 'Logged Co Updated']);

        $this->assertDatabaseHas('activity_logs', [
            'event_key' => 'contact.updated',
            'subject_id' => $contact->getKey(),
        ]);
    }
}
