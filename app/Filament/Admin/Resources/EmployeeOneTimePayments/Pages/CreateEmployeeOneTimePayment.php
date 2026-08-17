<?php

namespace App\Filament\Admin\Resources\EmployeeOneTimePayments\Pages;

use App\Filament\Admin\Resources\EmployeeOneTimePayments\EmployeeOneTimePaymentResource;
use Filament\Resources\Pages\CreateRecord;

class CreateEmployeeOneTimePayment extends CreateRecord
{
    protected static string $resource = EmployeeOneTimePaymentResource::class;
}
