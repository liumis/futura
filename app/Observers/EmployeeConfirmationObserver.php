<?php

namespace App\Observers;

use App\Models\Employee;
use App\Services\DocumentConfirmationService;

class EmployeeConfirmationObserver
{
    public function __construct(
        protected DocumentConfirmationService $confirmations,
    ) {}

    public function created(Employee $employee): void
    {
        $this->enrollIfEligible($employee);
    }

    public function updated(Employee $employee): void
    {
        if (! $employee->wasChanged(['user_id', 'status'])) {
            return;
        }

        $this->enrollIfEligible($employee);
    }

    protected function enrollIfEligible(Employee $employee): void
    {
        if (! $employee->isActive() || blank($employee->user_id)) {
            return;
        }

        $user = $employee->user()->first();

        if ($user === null) {
            return;
        }

        $this->confirmations->enrollUser($user);
    }
}
