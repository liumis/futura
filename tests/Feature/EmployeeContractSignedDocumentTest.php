<?php

namespace Tests\Feature;

use App\Enums\EmployeeContractStatus;
use App\Filament\Admin\Resources\EmployeeContracts\Pages\CreateEmployeeContract;
use App\Filament\Admin\Resources\EmployeeContracts\Pages\EditEmployeeContract;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use App\Services\EmployeeContractSigner;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeContractSignedDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-signed-contract@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_signed_document_field_is_hidden_until_status_is_signed(): void
    {
        $this->actingAs($this->admin);

        Livewire::test(CreateEmployeeContract::class)
            ->assertFormFieldIsHidden('signed_document')
            ->fillForm([
                'status' => EmployeeContractStatus::Signed->value,
            ])
            ->assertFormFieldIsVisible('signed_document');
    }

    public function test_creating_as_signed_requires_an_uploaded_document(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($this->admin);

        Livewire::test(CreateEmployeeContract::class)
            ->fillForm([
                'employee_id' => $employee->getKey(),
                'sign_date' => '2026-06-01',
                'effective_date_from' => '2026-06-01',
                'base_salary' => 2200,
                'status' => EmployeeContractStatus::Signed->value,
            ])
            ->call('create')
            ->assertHasFormErrors(['signed_document']);

        $this->assertSame(0, EmployeeContract::query()->count());
    }

    public function test_creating_as_signed_stores_document_and_links_it(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($this->admin);

        Livewire::test(CreateEmployeeContract::class)
            ->fillForm([
                'employee_id' => $employee->getKey(),
                'sign_date' => '2026-06-01',
                'effective_date_from' => '2026-06-01',
                'base_salary' => 2200,
                'status' => EmployeeContractStatus::Signed->value,
                'signed_document' => $this->fakeSignedDocumentPath('paper-copy.pdf'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contract = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->first();

        $this->assertNotNull($contract);
        $this->assertSame(EmployeeContractStatus::Signed, $contract->status);
        $this->assertNotNull($contract->document_id);

        $document = $contract->document;
        $this->assertNotNull($document);
        $this->assertTrue((bool) $document->flag_approved);
        $this->assertSame('Employee contract', $document->documentType?->name);
        $this->assertNotNull($document->file_path);
        $this->assertTrue(Storage::disk('public')->exists((string) $document->file_path));
    }

    public function test_creating_as_signed_accepts_docx_upload(): void
    {
        $employee = $this->makeEmployee();

        $this->actingAs($this->admin);

        Livewire::test(CreateEmployeeContract::class)
            ->fillForm([
                'employee_id' => $employee->getKey(),
                'sign_date' => '2026-06-01',
                'effective_date_from' => '2026-06-01',
                'base_salary' => 2200,
                'status' => EmployeeContractStatus::Signed->value,
                'signed_document' => $this->fakeSignedDocumentPath('paper-copy.docx', 'word-bytes'),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $contract = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->first();

        $this->assertNotNull($contract);
        $this->assertNotNull($contract->document_id);
        $this->assertNotNull($contract->document?->file_path);
        $this->assertStringEndsWith('.docx', (string) $contract->document->file_path);
        $this->assertTrue(Storage::disk('public')->exists((string) $contract->document->file_path));
    }

    public function test_attach_signed_upload_reuses_existing_document(): void
    {
        Storage::fake('public');

        $path = 'employee-contracts/replacement.pdf';
        Storage::disk('public')->put($path, "%PDF-1.4 replacement\n");

        $employee = $this->makeEmployee();
        $contract = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'base_salary' => 2000,
        ]);

        $first = EmployeeContractSigner::attachSignedUpload(
            $contract,
            $this->putSignedFile('employee-contracts/first.pdf', 'first'),
            $this->admin,
        );

        $second = EmployeeContractSigner::attachSignedUpload($contract->fresh(), $path, $this->admin);

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame($path, $second->file_path);
        $this->assertSame($first->getKey(), $contract->fresh()->document_id);
    }

    public function test_edit_can_attach_signed_document_when_status_becomes_signed(): void
    {
        $employee = $this->makeEmployee();
        $contract = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Draft,
            'sign_date' => '2026-06-01',
            'effective_date_from' => '2026-06-01',
            'base_salary' => 2200,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(EditEmployeeContract::class, ['record' => $contract->getKey()])
            ->fillForm([
                'status' => EmployeeContractStatus::Signed->value,
                'signed_document' => $this->fakeSignedDocumentPath('scan.png', 'image-bytes'),
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $contract->refresh();
        $this->assertSame(EmployeeContractStatus::Signed, $contract->status);
        $this->assertNotNull($contract->document_id);
        $this->assertNotNull($contract->document?->file_path);
        $this->assertTrue(Storage::disk('public')->exists((string) $contract->document->file_path));
    }

    protected function makeEmployee(): Employee
    {
        return Employee::query()->create([
            'name' => 'Ieva',
            'surname' => 'Signed',
            'working_time_percentage' => 100,
        ]);
    }

    protected function fakeSignedDocumentPath(string $name = 'signed-contract.pdf', string $contents = 'signed'): array
    {
        Storage::fake('public');

        return [$this->putSignedFile('employee-contracts/'.$name, $contents)];
    }

    protected function putSignedFile(string $path, string $contents): string
    {
        Storage::disk('public')->put($path, "%PDF-1.4 {$contents}\n");

        return $path;
    }
}
