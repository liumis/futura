<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\BankStatements;
use App\Jobs\ImportBankStatementJob;
use App\Models\BankStatementImport;
use App\Models\BankStatementTransaction;
use App\Models\SharepointSetting;
use App\Models\User;
use App\Notifications\BankStatementImportFinishedNotification;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ImportBankStatementJobTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-bank-pdf-job@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        SharepointSetting::instance()->update([
            'is_enabled' => true,
            'tenant_id' => 'tenant',
            'client_id' => 'client',
            'client_secret' => 'secret',
            'site_url' => 'https://contoso.sharepoint.com/sites/docs',
        ]);
    }

    public function test_pdf_upload_dispatches_import_job_after_response(): void
    {
        Bus::fake();

        $relative = 'bank-statements/async-test.pdf';
        Storage::disk('local')->put($relative, '%PDF-1.4 fake');

        $this->actingAs($this->admin);

        Livewire::test(BankStatements::class)
            ->set('data.file_paths', [$relative])
            ->set('data.comment', 'async pdf')
            ->call('import')
            ->assertNotified('PDF import started');

        Bus::assertDispatched(ImportBankStatementJob::class, function (ImportBankStatementJob $job) use ($relative): bool {
            return $job->relativePath === $relative
                && $job->originalFilename === 'async-test.pdf'
                && $job->userId === (int) $this->admin->getKey()
                && $job->comment === 'async pdf';
        });

        $this->assertSame(0, BankStatementImport::query()->count());
    }

    public function test_job_imports_pdf_via_ocr_and_notifies_user(): void
    {
        config(['services.ocr_space.key' => 'test-key']);
        Notification::fake();

        $ocrPath = base_path('tests/Fixtures/bank-statements/seb-statement-08m.ocr.txt');
        $pdfPath = base_path('tests/Fixtures/bank-statements/seb-statement-08m.pdf');
        $this->assertFileExists($pdfPath);

        $relative = 'bank-statements/seb-statement-08m.pdf';
        Storage::disk('local')->put($relative, (string) file_get_contents($pdfPath));

        Http::fake([
            'api.ocr.space/*' => Http::response([
                'ParsedResults' => [
                    ['ParsedText' => (string) file_get_contents($ocrPath)],
                ],
            ], 200),
        ]);

        $job = new ImportBankStatementJob(
            $relative,
            'seb-statement-08m.pdf',
            (int) $this->admin->getKey(),
            'job test',
        );
        $job->handle(app(\App\Services\BankStatements\BankStatementImporter::class));

        $this->assertGreaterThanOrEqual(20, BankStatementTransaction::query()->count());
        $this->assertSame('LT117044090110985504', BankStatementImport::query()->value('account_iban'));

        Notification::assertSentTo(
            $this->admin,
            BankStatementImportFinishedNotification::class,
            function (BankStatementImportFinishedNotification $notification): bool {
                return $notification->success === true
                    && str_contains($notification->body, 'new line(s) stored');
            },
        );
    }
}
