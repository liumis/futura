<?php

namespace App\Enums;

use UnitEnum;

enum AdminModule: string
{
    case Orders = 'orders';
    case WarehouseShipping = 'warehouse_shipping';
    case Reports = 'reports';
    case Catalog = 'catalog';
    case Contacts = 'contacts';
    case Finances = 'finances';
    case Documents = 'documents';
    case Tasks = 'tasks';
    case EmployeesContracts = 'employees_contracts';
    case System = 'system';
    case FinancialOptions = 'financial_options';

    public function label(): string
    {
        return match ($this) {
            self::Orders => 'Orders',
            self::WarehouseShipping => 'Stock',
            self::Reports => 'Reports',
            self::Catalog => 'Catalog',
            self::Contacts => 'Contacts',
            self::Finances => 'Finances',
            self::Documents => 'Documents',
            self::Tasks => 'Tasks',
            self::EmployeesContracts => 'Employees & contracts',
            self::System => 'System',
            self::FinancialOptions => 'Financial options',
        };
    }

    public function navigationGroup(): string
    {
        return $this->label();
    }

    /**
     * Modules always granted to project managers (not shown as "additional").
     */
    public static function defaultForProjectManager(): self
    {
        return self::Tasks;
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    /**
     * Additional modules that can be assigned to a project manager.
     *
     * @return array<string, string>
     */
    public static function additionalOptionsForProjectManager(): array
    {
        $default = self::defaultForProjectManager();

        return collect(self::cases())
            ->reject(fn (self $case): bool => $case === $default)
            ->mapWithKeys(fn (self $case): array => [$case->value => $case->label()])
            ->all();
    }

    public static function tryFromNavigationGroup(null|string|UnitEnum $group): ?self
    {
        if ($group instanceof UnitEnum) {
            $group = $group instanceof self
                ? $group->navigationGroup()
                : (string) $group->value;
        }

        if (! is_string($group) || $group === '') {
            return null;
        }

        foreach (self::cases() as $case) {
            if ($case->navigationGroup() === $group) {
                return $case;
            }
        }

        return null;
    }
}
