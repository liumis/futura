<?php

namespace Tests\Feature;

use App\Enums\EmployeeContractSigningStatus;
use App\Enums\EmployeeContractStatus;
use App\Enums\EmployeeStatus;
use App\Filament\Admin\Resources\EmployeeContracts\Pages\ListEmployeeContracts;
use App\Filament\Admin\Resources\Employees\Pages\ListEmployees;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\EmployeeContractSigning;
use App\Models\User;
use App\Services\EmployeeContractSigner;
use App\Services\EmployeeContractTerminationPdfGenerator;
use App\Services\EmployeeContractTerminator;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class EmployeeContractTerminationTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-end-contract@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_uploading_signed_termination_inactivates_employee_and_contract(): void
    {
        Storage::fake('public');

        $employee = $this->makeEmployee();
        $contract = $this->makeSignedContract($employee, '2026-01-01');
        $originalDocumentId = $contract->document_id;

        $path = $this->putSignedFile('employee-contracts/termination-scan.pdf', 'ended');

        EmployeeContractTerminator::attachSignedUpload(
            $contract,
            '2026-09-16',
            'Mutual agreement',
            $path,
            $this->admin,
        );

        $contract->refresh();
        $employee->refresh();

        $this->assertSame(EmployeeContractStatus::Inactive, $contract->status);
        $this->assertSame('2026-09-16', $contract->termination_date?->toDateString());
        $this->assertSame('Mutual agreement', $contract->termination_reason);
        $this->assertSame('2026-09-16', $contract->valid_to?->toDateString());
        $this->assertSame($originalDocumentId, $contract->document_id);
        $this->assertNotNull($contract->termination_document_id);
        $this->assertTrue((bool) $contract->terminationDocument?->flag_approved);
        $this->assertSame('Employee contract termination', $contract->terminationDocument?->documentType?->name);
        $this->assertSame(EmployeeStatus::Inactive, $employee->status);
        $this->assertSame('2026-09-16', $employee->contract_end_date?->toDateString());
    }

    public function test_termination_upload_keeps_multiple_files(): void
    {
        Storage::fake('public');

        $employee = $this->makeEmployee();
        $contract = $this->makeSignedContract($employee, '2026-01-01');
        $first = $this->putSignedFile('employee-contracts/termination-scan.pdf', 'ended');
        $second = $this->putSignedFile('employee-contracts/termination-annex.pdf', 'annex');

        $document = EmployeeContractTerminator::attachSignedUploads(
            $contract,
            '2026-09-16',
            'Mutual agreement',
            [$first, $second],
            $this->admin,
        );

        $this->assertSame(2, $document->attachedFilesCount());
        $names = collect($document->attachedFileLinks())->pluck('name')->all();
        $this->assertContains('termination-scan.pdf', $names);
        $this->assertContains('termination-annex.pdf', $names);

        EmployeeContractTerminator::attachAdditionalFiles(
            $contract->fresh(['terminationDocument']),
            [$this->putSignedFile('employee-contracts/termination-extra.pdf', 'extra')],
            $this->admin,
        );

        $this->assertSame(3, $contract->fresh('terminationDocument')->terminationDocument?->attachedFilesCount());
    }

    public function test_employees_list_shows_termination_date_and_document_link(): void
    {
        Storage::fake('public');

        $employee = $this->makeEmployee(['name' => 'Ended', 'surname' => 'Listed']);
        $contract = $this->makeSignedContract($employee, '2026-01-01');

        EmployeeContractTerminator::attachSignedUpload(
            $contract,
            '2026-09-16',
            'Mutual agreement',
            $this->putSignedFile('employee-contracts/listed-end.pdf', 'ended'),
            $this->admin,
        );

        $this->actingAs($this->admin);

        Livewire::test(ListEmployees::class)
            ->assertOk()
            ->assertSee('2026-09-16')
            ->assertSee('listed-end.pdf');
    }

    public function test_employee_stays_active_while_termination_document_is_unsigned(): void
    {
        Storage::fake('public');

        $employee = $this->makeEmployee();
        $contract = $this->makeSignedContract($employee, '2026-01-01');
        $document = $this->makeTerminationDocument($contract);

        $contract->update([
            'termination_date' => '2026-09-20',
            'termination_reason' => 'Waiting for signatures',
            'termination_document_id' => $document->getKey(),
        ]);

        $this->assertTrue($contract->fresh()->canEnd());
        $this->assertTrue($contract->fresh()->hasPendingTermination());
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
        $this->assertSame(EmployeeContractStatus::Signed, $contract->fresh()->status);
        $this->assertFalse($contract->fresh()->canStartSigning());
    }

    public function test_completing_termination_signing_inactivates_employee(): void
    {
        Storage::fake('public');

        $employee = $this->makeEmployee();
        $contract = $this->makeSignedContract($employee, '2026-01-01');
        $document = $this->makeTerminationDocument($contract);

        $contract->update([
            'termination_date' => '2026-09-16',
            'termination_reason' => 'Signed in Dokobit',
            'termination_document_id' => $document->getKey(),
        ]);

        $signing = EmployeeContractSigning::query()->create([
            'employee_contract_id' => $contract->getKey(),
            'document_id' => $document->getKey(),
            'name' => 'Employment contract termination — '.$employee->fullName(),
            'status' => EmployeeContractSigningStatus::Pending,
            'created_by' => $this->admin->getKey(),
        ]);

        EmployeeContractSigner::complete($signing, "%PDF-1.4 signed-termination\n");

        $contract->refresh();
        $employee->refresh();
        $document->refresh();

        $this->assertTrue($signing->fresh()->isCompleted());
        $this->assertTrue((bool) $document->flag_approved);
        $this->assertSame(EmployeeContractStatus::Inactive, $contract->status);
        $this->assertSame(EmployeeStatus::Inactive, $employee->status);
        $this->assertNotSame($contract->document_id, $contract->termination_document_id);
    }

    public function test_other_signed_contract_keeps_employee_active(): void
    {
        Storage::fake('public');

        $employee = $this->makeEmployee();
        $ending = $this->makeSignedContract($employee, '2025-01-01');
        $this->makeSignedContract($employee, '2026-06-01');

        EmployeeContractTerminator::attachSignedUpload(
            $ending,
            '2026-05-31',
            'Replaced by a new contract',
            $this->putSignedFile('employee-contracts/old-end.pdf', 'old'),
            $this->admin,
        );

        $this->assertSame(EmployeeContractStatus::Inactive, $ending->fresh()->status);
        $this->assertSame(EmployeeStatus::Active, $employee->fresh()->status);
    }

    public function test_end_contract_action_is_visible_on_employees_and_contracts_lists(): void
    {
        $signedEmployee = $this->makeEmployee(['name' => 'Signed', 'surname' => 'Person']);
        $draftEmployee = $this->makeEmployee(['name' => 'Draft', 'surname' => 'Person']);
        $signedContract = $this->makeSignedContract($signedEmployee, '2026-01-01');
        $draftContract = EmployeeContract::query()->create([
            'employee_id' => $draftEmployee->getKey(),
            'status' => EmployeeContractStatus::Draft,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'base_salary' => 1800,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(ListEmployees::class)
            ->assertOk()
            ->assertTableActionVisible('endContract', $signedEmployee)
            ->assertTableActionHidden('endContract', $draftEmployee);

        Livewire::test(ListEmployeeContracts::class)
            ->assertOk()
            ->assertTableActionVisible('endContract', $signedContract)
            ->assertTableActionHidden('endContract', $draftContract);
    }

    public function test_employees_list_can_end_contract_by_uploading_signed_document(): void
    {
        Storage::fake('public');

        $employee = $this->makeEmployee();
        $this->makeSignedContract($employee, '2026-01-01');
        $path = $this->putSignedFile('employee-contracts/ui-end.pdf', 'ui');

        $this->actingAs($this->admin);

        Livewire::test(ListEmployees::class)
            ->callTableAction('endContract', $employee, [
                'termination_date' => '2026-09-16',
                'termination_reason' => 'Resignation',
                'signed_document' => [$path],
            ])
            ->assertHasNoTableActionErrors();

        $this->assertSame(EmployeeStatus::Inactive, $employee->fresh()->status);
        $this->assertSame(
            EmployeeContractStatus::Inactive,
            EmployeeContract::query()->where('employee_id', $employee->getKey())->first()?->status,
        );
    }

    public function test_termination_pdf_contains_date_and_reason(): void
    {
        $employee = $this->makeEmployee();
        $contract = $this->makeSignedContract($employee, '2026-01-01');
        $contract->update([
            'termination_date' => '2026-09-16',
            'termination_reason' => 'Mutual agreement PDF',
        ]);

        $binary = EmployeeContractTerminationPdfGenerator::generate($contract->fresh('employee'));

        $this->assertNotSame('', $binary);
        $this->assertStringContainsString('%PDF', $binary);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function makeEmployee(array $overrides = []): Employee
    {
        return Employee::query()->create(array_merge([
            'name' => 'Ieva',
            'surname' => 'Ended',
            'working_time_percentage' => 100,
            'status' => EmployeeStatus::Active,
        ], $overrides));
    }

    protected function makeSignedContract(Employee $employee, string $from): EmployeeContract
    {
        $type = DocumentType::query()->firstOrCreate(['name' => 'Employee contract']);
        $document = Document::query()->create([
            'document_date' => $from,
            'name' => 'Employment contract — '.$employee->fullName(),
            'document_type_id' => $type->getKey(),
            'user_uploaded_id' => $this->admin->getKey(),
            'flag_approved' => true,
            'content_hash' => hash('sha256', $from),
            'pdf_hash' => hash('sha256', $from),
        ]);

        return EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => $from,
            'effective_date_from' => $from,
            'base_salary' => 2000,
            'document_id' => $document->getKey(),
        ]);
    }

    protected function makeTerminationDocument(EmployeeContract $contract): Document
    {
        $type = DocumentType::query()->firstOrCreate(['name' => 'Employee contract termination']);

        return Document::query()->create([
            'document_date' => now(),
            'name' => 'Employment contract termination — '.$contract->employee?->fullName(),
            'document_type_id' => $type->getKey(),
            'user_uploaded_id' => $this->admin->getKey(),
            'flag_approved' => false,
            'content_hash' => hash('sha256', 'unsigned'),
            'pdf_hash' => hash('sha256', 'unsigned'),
        ]);
    }

    protected function putSignedFile(string $path, string $contents): string
    {
        Storage::disk('public')->put($path, "%PDF-1.4 {$contents}\n");

        return $path;
    }
}
