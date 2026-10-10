<?php

namespace Tests\Unit;

use App\Enums\EmployeeContractStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmployeeContractOverlapTest extends TestCase
{
    use RefreshDatabase;

    public function test_warns_when_new_contract_starts_while_another_is_active(): void
    {
        $employee = $this->makeEmployee();

        $existing = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'valid_to' => null,
            'base_salary' => 2000,
        ]);

        $message = EmployeeContract::overlapWarningMessage(
            employeeId: (int) $employee->getKey(),
            effectiveFrom: '2026-06-01',
        );

        $this->assertNotNull($message);
        $this->assertStringContainsString('#'.$existing->getKey(), $message);
        $this->assertStringContainsString('You can still save', $message);
    }

    public function test_does_not_warn_when_previous_contract_already_ended(): void
    {
        $employee = $this->makeEmployee();

        EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Inactive,
            'sign_date' => '2025-01-01',
            'effective_date_from' => '2025-01-01',
            'valid_to' => '2025-12-31',
            'base_salary' => 1800,
        ]);

        $this->assertNull(EmployeeContract::overlapWarningMessage(
            employeeId: (int) $employee->getKey(),
            effectiveFrom: '2026-01-01',
        ));
    }

    public function test_signing_closes_overlapping_contracts_with_end_date(): void
    {
        $employee = $this->makeEmployee();

        $previous = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'valid_to' => null,
            'base_salary' => 2000,
        ]);

        $next = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Draft,
            'sign_date' => '2026-06-01',
            'effective_date_from' => '2026-06-01',
            'base_salary' => 2200,
        ]);

        $this->assertSame(0, $next->closeOverlappingContracts());
        $this->assertNull($previous->fresh()->valid_to);

        $next->update(['status' => EmployeeContractStatus::Signed]);
        $closed = $next->fresh()->closeOverlappingContracts();

        $this->assertSame(1, $closed);
        $previous->refresh();
        $this->assertSame('2026-05-31', $previous->valid_to?->toDateString());
        $this->assertSame(EmployeeContractStatus::Inactive, $previous->status);
        $this->assertNull($next->fresh()->valid_to);
    }

    public function test_signing_shortens_an_existing_later_end_date(): void
    {
        $employee = $this->makeEmployee();

        $previous = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'valid_to' => '2026-12-31',
            'base_salary' => 2000,
        ]);

        $next = EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-06-01',
            'effective_date_from' => '2026-06-01',
            'base_salary' => 2200,
        ]);

        $this->assertSame(1, $next->closeOverlappingContracts());
        $this->assertSame('2026-05-31', $previous->fresh()->valid_to?->toDateString());
    }

    public function test_signing_does_not_close_another_employees_contract(): void
    {
        $one = $this->makeEmployee('Ada');
        $two = $this->makeEmployee('Bea');

        $other = EmployeeContract::query()->create([
            'employee_id' => $two->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-01-01',
            'effective_date_from' => '2026-01-01',
            'base_salary' => 2000,
        ]);

        $signed = EmployeeContract::query()->create([
            'employee_id' => $one->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => '2026-06-01',
            'effective_date_from' => '2026-06-01',
            'base_salary' => 2200,
        ]);

        $this->assertSame(0, $signed->closeOverlappingContracts());
        $this->assertNull($other->fresh()->valid_to);
        $this->assertSame(EmployeeContractStatus::Signed, $other->fresh()->status);
    }

    protected function makeEmployee(string $name = 'Jonas'): Employee
    {
        return Employee::query()->create([
            'name' => $name,
            'surname' => 'Test',
            'working_time_percentage' => 100,
        ]);
    }
}
