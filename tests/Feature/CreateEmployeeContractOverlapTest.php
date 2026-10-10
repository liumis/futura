<?php

namespace Tests\Feature;

use App\Enums\EmployeeContractStatus;
use App\Filament\Admin\Resources\EmployeeContracts\EmployeeContractResource;
use App\Filament\Admin\Resources\EmployeeContracts\Pages\CreateEmployeeContract;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CreateEmployeeContractOverlapTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Role::findOrCreate('admin');

        $this->admin = User::factory()->create([
            'email' => 'admin-contracts@example.com',
        ]);
        $this->admin->assignRole('admin');

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_create_page_loads_for_admin(): void
    {
        $this->actingAs($this->admin);

        $this->get(EmployeeContractResource::getUrl('create'))
            ->assertOk();
    }

    public function test_overlapping_draft_saves_without_being_forced_inactive(): void
    {
        $employee = $this->makeEmployee();

        EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'base_salary' => 2000,
        ]);

        $this->actingAs($this->admin);

        Livewire::test(CreateEmployeeContract::class)
            ->fillForm([
                'employee_id' => $employee->getKey(),
                'sign_date' => '2026-06-01',
                'effective_date_from' => '2026-06-01',
                'base_salary' => 2200,
                'status' => EmployeeContractStatus::Draft->value,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $created = EmployeeContract::query()
            ->where('employee_id', $employee->getKey())
            ->whereDate('effective_date_from', '2026-06-01')
            ->first();

        $this->assertNotNull($created);
        $this->assertSame(EmployeeContractStatus::Draft, $created->status);
        $this->assertNull($created->valid_to);
    }

    public function test_creating_as_signed_sets_end_date_on_overlapping_contracts(): void
    {
        $employee = $this->makeEmployee();

        $previous = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'base_salary' => 2000,
        ]);

        $this->actingAs($this->admin);

        Storage::fake('public');
        $path = 'employee-contracts/signed-contract.pdf';
        Storage::disk('public')->put($path, "%PDF-1.4 signed\n");

        Livewire::test(CreateEmployeeContract::class)
            ->fillForm([
                'employee_id' => $employee->getKey(),
                'sign_date' => '2026-06-01',
                'effective_date_from' => '2026-06-01',
                'base_salary' => 2200,
                'status' => EmployeeContractStatus::Signed->value,
                'signed_document' => [$path],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $previous->refresh();
        $this->assertSame('2026-05-31', $previous->valid_to?->toDateString());
        $this->assertSame(EmployeeContractStatus::Inactive, $previous->status);
    }

    protected function makeEmployee(): Employee
    {
        return Employee::query()->create([
            'name' => 'Jonas',
            'surname' => 'Overlap',
            'working_time_percentage' => 100,
        ]);
    }
}
