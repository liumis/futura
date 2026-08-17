<?php

namespace App\Models;

use App\Enums\EmployeeNpdType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Employee extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'surname',
        'birthdate',
        'position',
        'bank_account',
        'contract_signed_date',
        'contract_end_date',
        'phone',
        'email',
        'working_time_percentage',
        'shareholder_percentage',
        'npd_type',
        'second_pillar_enrolled',
        'second_pillar_rate',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'contract_signed_date' => 'date',
            'contract_end_date' => 'date',
            'working_time_percentage' => 'decimal:2',
            'shareholder_percentage' => 'decimal:2',
            'npd_type' => EmployeeNpdType::class,
            'second_pillar_enrolled' => 'boolean',
            'second_pillar_rate' => 'decimal:4',
        ];
    }

    public function fullName(): string
    {
        return trim($this->name.' '.$this->surname);
    }

    public function workSchedules(): HasMany
    {
        return $this->hasMany(WorkSchedule::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function overtimeRequests(): HasMany
    {
        return $this->hasMany(OvertimeRequest::class);
    }

    public function employeeContracts(): HasMany
    {
        return $this->hasMany(EmployeeContract::class);
    }

    public function oneTimePayments(): HasMany
    {
        return $this->hasMany(EmployeeOneTimePayment::class);
    }

    public function monthlyPayments(): HasMany
    {
        return $this->hasMany(EmployeeMonthlyPayment::class);
    }

    public function dividends(): HasMany
    {
        return $this->hasMany(Dividend::class);
    }
}
