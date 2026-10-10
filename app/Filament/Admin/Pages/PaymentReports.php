<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AdminModule;
use Filament\Pages\Page;

class PaymentReports extends Page
{
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.payment-reports';

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessModule(AdminModule::EmployeesContracts) ?? false;
    }

    public function mount(): void
    {
        $this->redirect(MonthlyPayment::getUrl(), navigate: true);
    }
}
