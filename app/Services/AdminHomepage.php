<?php

namespace App\Services;

use App\Enums\AdminModule;
use App\Filament\Admin\Pages\Dashboard;
use App\Filament\Admin\Pages\Invoices;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Employees\EmployeeResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Filament\Admin\Resources\Products\ProductResource;
use App\Filament\Admin\Resources\Tasks\TaskResource;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Resources\VatRates\VatRateResource;
use App\Models\User;
use Throwable;

final class AdminHomepage
{
    /**
     * @return array<string, string>
     */
    public static function optionsFor(User $user): array
    {
        $options = [
            '' => 'Dashboard',
        ];

        foreach (AdminModule::cases() as $module) {
            if ($user->canAccessModule($module)) {
                $options[$module->value] = $module->label();
            }
        }

        return $options;
    }

    public static function urlFor(?User $user): string
    {
        $fallback = self::dashboardUrl();

        if ($user === null) {
            return $fallback;
        }

        $module = self::resolveModule($user);

        if ($module === null) {
            return $fallback;
        }

        try {
            return self::urlForModule($module);
        } catch (Throwable) {
            return $fallback;
        }
    }

    public static function urlForModule(AdminModule $module): string
    {
        return match ($module) {
            AdminModule::Orders => OrderResource::getUrl('index'),
            AdminModule::WarehouseShipping => CargoResource::getUrl('index'),
            AdminModule::Reports => self::dashboardUrl(),
            AdminModule::Catalog => ProductResource::getUrl('index'),
            AdminModule::Contacts => DirectoryContactResource::getUrl('index'),
            AdminModule::Finances => Invoices::getUrl(),
            AdminModule::Documents => DocumentResource::getUrl('index'),
            AdminModule::Tasks => TaskResource::getUrl('index'),
            AdminModule::EmployeesContracts => EmployeeResource::getUrl('index'),
            AdminModule::System => UserResource::getUrl('index'),
            AdminModule::FinancialOptions => VatRateResource::getUrl('index'),
        };
    }

    public static function normalize(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '' || AdminModule::tryFrom($value) === null) {
            return null;
        }

        return $value;
    }

    private static function resolveModule(User $user): ?AdminModule
    {
        $value = self::normalize($user->homepage_module);

        if ($value === null) {
            return null;
        }

        $module = AdminModule::tryFrom($value);

        if ($module === null || ! $user->canAccessModule($module)) {
            return null;
        }

        return $module;
    }

    private static function dashboardUrl(): string
    {
        return Dashboard::getUrl();
    }
}
