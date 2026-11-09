<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\Invoices;
use App\Jobs\ImportInvoiceUploadJob;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Notifications\InvoiceUploadFinishedNotification;
use App\Services\InvoiceFromUploadCreator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class InvoiceBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');
        $this->admin = User::factory()->create([
            'email' => 'admin-invoice-bulk@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        SharepointSetting::instance()->update([
            'is_enabled' => true,
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'site_id' => 'site-1',
            'drive_id' => 'drive-1',
            'site_url' => 'https://contoso.sharepoint.com/sites/docs',
            'root_folder_path' => 'SS Documents',
        ]);

        Storage::fake('public');

        Http::fake([
            'login.microsoftonline.com/*' => Http::response([
                'access_token' => 'token',
                'expires_in' => 3600,
            ], 200),
            'graph.microsoft.com/*' => Http::response([
                'id' => 'item-1',
                'webUrl' => 'https://sharepoint.example/invoices/file.pdf',
                'name' => 'file.pdf',
            ], 200),
        ]);
    }

    public function test_bulk_upload_queues_jobs_after_response(): void
    {
        Bus::fake();

        $path = 'invoices/bulk/one.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 fake');

        $this->actingAs($this->admin);

        Livewire::test(Invoices::class)
            ->set('bulkUpload.paths', [$path])
            ->call('importBulkUploads')
            ->assertNotified('Import started')
            ->assertSet('filters.month', 'any')
            ->assertSet('sortColumn', 'upload_date');

        Bus::assertDispatched(ImportInvoiceUploadJob::class, function (ImportInvoiceUploadJob $job) use ($path): bool {
            return $job->relativePath === $path
                && $job->originalFilename === 'one.pdf'
                && $job->userId === (int) $this->admin->getKey();
        });
    }

    public function test_creator_saves_incomplete_ocr_as_draft(): void
    {
        $path = 'invoices/bulk/scan.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 weak');

        $ocr = Mockery::mock(\App\Services\InvoiceOcrService::class);
        $ocr->shouldReceive('extract')->once()->andReturn([
            'provider_company_name' => 'ab',
        ]);
        $ocr->shouldReceive('isCompleteParse')->once()->andReturn(false);
        $this->app->instance(\App\Services\InvoiceOcrService::class, $ocr);

        $result = app(InvoiceFromUploadCreator::class)->createFromStoredPath(
            $path,
            'public',
            (int) $this->admin->getKey(),
            'scan.pdf',
        );

        $this->assertSame('draft', $result['status']);
        $this->assertTrue($result['invoice']?->isDraft());
        $this->assertSame(1, Invoice::query()->where('is_draft', true)->count());
    }

    public function test_creator_keeps_complete_ocr_invoice_as_draft_until_review(): void
    {
        Contact::query()->create([
            'company_name' => 'Acme UAB',
            'company_id' => '123456789',
            'company_address' => 'Vilnius',
        ]);

        $path = 'invoices/bulk/good.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4 good');

        $ocr = Mockery::mock(\App\Services\InvoiceOcrService::class);
        $ocr->shouldReceive('extract')->once()->andReturn([
            'provider_company_name' => 'Acme UAB',
            'provider_company_id' => '123456789',
            'invoice_number' => 'INV-BULK-1',
            'invoice_date' => '2026-10-15',
            'sum_without_vat' => 100.0,
            'vat' => 21.0,
            'sum_inc_vat' => 121.0,
            'currency' => 'EUR',
        ]);
        $ocr->shouldReceive('isCompleteParse')->once()->andReturn(true);
        $this->app->instance(\App\Services\InvoiceOcrService::class, $ocr);

        $result = app(InvoiceFromUploadCreator::class)->createFromStoredPath(
            $path,
            'public',
            (int) $this->admin->getKey(),
            'good.pdf',
        );

        $this->assertSame('draft', $result['status']);
        $this->assertTrue($result['invoice']?->isDraft());
        $this->assertSame('INV-BULK-1', Invoice::query()->value('invoice_number'));
        $this->assertNotNull(Invoice::query()->value('sharepoint_web_url'));
        $this->assertStringContainsString('Confirm & publish', (string) $result['message']);
    }

    public function test_job_notifies_user_on_draft(): void
    {
        Notification::fake();

        $path = 'invoices/bulk/job.pdf';
        Storage::disk('public')->put($path, '%PDF-1.4');

        $ocr = Mockery::mock(\App\Services\InvoiceOcrService::class);
        $ocr->shouldReceive('extract')->once()->andReturn([]);
        $ocr->shouldReceive('isCompleteParse')->never();
        $this->app->instance(\App\Services\InvoiceOcrService::class, $ocr);

        $job = new ImportInvoiceUploadJob($path, 'job.pdf', (int) $this->admin->getKey(), 'public');
        $job->handle(app(InvoiceFromUploadCreator::class));

        Notification::assertSentTo(
            $this->admin,
            InvoiceUploadFinishedNotification::class,
            fn (InvoiceUploadFinishedNotification $notification): bool => $notification->status === 'draft',
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
