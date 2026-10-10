<?php

namespace Tests\Unit;

use App\Enums\EmployeeContractStatus;
use App\Models\CompanySetting;
use App\Models\Employee;
use App\Models\EmployeeChild;
use App\Models\EmployeeContract;
use App\Models\LeaveRequestType;
use App\Services\AnnualLeaveCalculator;
use App\Services\ParentalLeaveCalculator;
use App\Services\SepaPain001Exporter;
use App\Services\WorkTimeTimesheetPdfGenerator;
use App\Support\TimesheetCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LeaveAndPayrollSupportTest extends TestCase
{
    use RefreshDatabase;

    public function test_seniority_extra_days_follow_lithuanian_thresholds(): void
    {
        $this->assertSame(0, AnnualLeaveCalculator::seniorityExtraDays(0));
        $this->assertSame(0, AnnualLeaveCalculator::seniorityExtraDays(9));
        $this->assertSame(3, AnnualLeaveCalculator::seniorityExtraDays(10));
        $this->assertSame(3, AnnualLeaveCalculator::seniorityExtraDays(14));
        $this->assertSame(4, AnnualLeaveCalculator::seniorityExtraDays(15));
        $this->assertSame(5, AnnualLeaveCalculator::seniorityExtraDays(20));
        $this->assertSame(6, AnnualLeaveCalculator::seniorityExtraDays(25));
    }

    public function test_annual_leave_includes_seniority_after_ten_years(): void
    {
        $employee = Employee::query()->create([
            'name' => 'Jonas',
            'surname' => 'Jonaitis',
            'working_time_percentage' => 100,
        ]);

        EmployeeContract::query()->create([
            'employee_id' => $employee->getKey(),
            'status' => EmployeeContractStatus::Signed,
            'sign_date' => Carbon::parse('2014-08-21'),
            'effective_date_from' => Carbon::parse('2014-08-21'),
            'base_salary' => 2000,
        ]);

        $result = AnnualLeaveCalculator::calculate($employee, '2026-08-21');

        $this->assertTrue($result['ok']);
        $this->assertSame(12, $result['completed_years']);
        $this->assertSame(3, $result['seniority_extra_days']);
        $this->assertSame(23, $result['days_per_year']);
        $this->assertGreaterThan(20, $result['accrued_days']);
    }

    public function test_parental_leave_one_child_is_quarterly(): void
    {
        $employee = Employee::query()->create([
            'name' => 'Ona',
            'surname' => 'Onaitė',
            'working_time_percentage' => 100,
        ]);

        EmployeeChild::query()->create([
            'employee_id' => $employee->getKey(),
            'birthdate' => '2020-01-15',
            'has_disability' => false,
        ]);

        $result = ParentalLeaveCalculator::calculate($employee, '2026-08-21');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['children_under_12']);
        $this->assertSame('quarter', $result['period']);
        $this->assertSame(1.0, $result['entitlement_days']);
    }

    public function test_parental_leave_two_children_is_monthly(): void
    {
        $employee = Employee::query()->create([
            'name' => 'Petras',
            'surname' => 'Petraitis',
            'working_time_percentage' => 100,
        ]);

        EmployeeChild::query()->create([
            'employee_id' => $employee->getKey(),
            'birthdate' => '2018-05-01',
            'has_disability' => false,
        ]);
        EmployeeChild::query()->create([
            'employee_id' => $employee->getKey(),
            'birthdate' => '2021-09-01',
            'has_disability' => false,
        ]);

        $result = ParentalLeaveCalculator::calculate($employee, '2026-08-21');

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['children_under_12']);
        $this->assertSame('month', $result['period']);
        $this->assertSame(1.0, $result['entitlement_days']);
    }

    public function test_parental_leave_three_children_get_two_days_monthly(): void
    {
        $employee = Employee::query()->create([
            'name' => 'Ada',
            'surname' => 'Adaitė',
            'working_time_percentage' => 100,
        ]);

        foreach (['2017-01-01', '2019-01-01', '2022-01-01'] as $birthdate) {
            EmployeeChild::query()->create([
                'employee_id' => $employee->getKey(),
                'birthdate' => $birthdate,
                'has_disability' => false,
            ]);
        }

        $result = ParentalLeaveCalculator::calculate($employee, '2026-08-21');

        $this->assertTrue($result['ok']);
        $this->assertSame(3, $result['children_under_12']);
        $this->assertSame('month', $result['period']);
        $this->assertSame(2.0, $result['entitlement_days']);
    }

    public function test_timesheet_codes_and_leave_name_fallback(): void
    {
        $codes = TimesheetCode::legendCodes();

        $this->assertContains('A', $codes);
        $this->assertContains('L', $codes);
        $this->assertContains('M', $codes);
        $this->assertArrayHasKey('A', TimesheetCode::assignableOptions());
        $this->assertArrayNotHasKey('P', TimesheetCode::assignableOptions());
        $this->assertSame('A', WorkTimeTimesheetPdfGenerator::codeForLeaveType('Kasmetinės atostogos'));
        $this->assertSame('L', WorkTimeTimesheetPdfGenerator::codeForLeaveType('Nedarbingumas'));
        $this->assertSame('NA', WorkTimeTimesheetPdfGenerator::codeForLeaveType('Neapmokamos atostogos'));
        $this->assertSame('M', WorkTimeTimesheetPdfGenerator::codeForLeaveType('Tėvadienis / Mamadienis'));
    }

    public function test_sepa_salary_xml_includes_sala_category_purpose(): void
    {
        $exporter = app(SepaPain001Exporter::class);
        $companyIban = 'LT121000011101001000';

        $this->assertTrue($exporter->isValidIban($companyIban), 'Fixture company IBAN must be valid');

        $company = CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => $companyIban,
            'company_bic' => 'AGBLLT2X',
        ]);

        $xml = $exporter->buildXmlFromTransactions(
            [[
                'id' => 1,
                'name' => 'Jonas Jonaitis',
                'iban' => $companyIban,
                'amount' => 100.50,
                'remittance' => 'Salary 2026-08',
            ]],
            $company,
            Carbon::parse('2026-08-21'),
            'SAL',
            'SALA',
        );

        $this->assertStringContainsString('<Cd>SALA</Cd>', $xml);
        $this->assertStringContainsString('<Cd>SEPA</Cd>', $xml);
        $this->assertStringContainsString('xmlns="urn:iso:std:iso:20022:tech:xsd:pain.001.001.03"', $xml);
        $this->assertStringContainsString('<ReqdExctnDt>2026-08-21</ReqdExctnDt>', $xml);
        $this->assertStringNotContainsString('<BICFI>', $xml);
        $this->assertStringContainsString('<BIC>AGBLLT2X</BIC>', $xml);
        $this->assertMatchesRegularExpression(
            '/<DbtrAgt>.*<BIC>AGBLLT2X<\/BIC>.*<\/DbtrAgt>\s*<ChrgBr>SLEV<\/ChrgBr>/s',
            $xml,
        );
    }

    public function test_sepa_derives_seb_bic_from_iban_when_missing(): void
    {
        $exporter = app(SepaPain001Exporter::class);
        $companyIban = 'LT121000011101001000';

        // LT12 10000 ... — bank code 10000 is unknown; use a SEB IBAN pattern 70440
        $sebIban = 'LT057044060007887175';
        $this->assertTrue($exporter->isValidIban($sebIban));
        $this->assertSame('CBVILT2X', $exporter->resolveDebtorBic('', $sebIban));

        $company = CompanySetting::query()->create([
            'company_name' => 'UAB Test',
            'company_iban' => $sebIban,
            'company_bic' => null,
        ]);

        $xml = $exporter->buildXmlFromTransactions(
            [[
                'id' => 1,
                'name' => 'Jonas Jonaitis',
                'iban' => $companyIban,
                'amount' => 10.00,
                'remittance' => 'Salary',
            ]],
            $company,
            Carbon::parse('2026-08-21'),
            'SAL',
            'SALA',
        );

        $this->assertStringContainsString('<BIC>CBVILT2X</BIC>', $xml);
        $this->assertStringContainsString('<DbtrAgt>', $xml);
    }

    public function test_leave_request_type_stores_timesheet_code(): void
    {
        $type = LeaveRequestType::query()->create([
            'name' => 'Kasmetinės atostogos Test',
            'color' => '#3b82f6',
            'timesheet_code' => 'A',
        ]);

        $this->assertSame('A', $type->fresh()->timesheet_code);
    }
}
