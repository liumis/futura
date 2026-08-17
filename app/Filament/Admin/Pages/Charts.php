<?php

namespace App\Filament\Admin\Pages;

use App\Models\Todo;
use BackedEnum;
use Filament\Pages\Page;
use Illuminate\Support\Carbon;
use UnitEnum;

class Charts extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-chart-bar';

    protected static ?string $navigationLabel = 'Charts';

    protected static string|UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.charts';

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getChartItems(): array
    {
        return Todo::query()
            ->notArchived()
            ->with('user:id,email,name')
            ->orderBy('deadline')
            ->get(['user_id', 'deadline', 'status', 'priority', 'total_payment', 'payment_left', 'total_income', 'income_left'])
            ->map(function (Todo $todo): array {
                $day = $todo->deadline instanceof Carbon
                    ? $todo->deadline->copy()->startOfDay()->format('Y-m-d')
                    : Carbon::parse((string) $todo->deadline)->startOfDay()->format('Y-m-d');

                $hasIncome = ($todo->total_income !== null && $todo->total_income !== '')
                    || ($todo->income_left !== null && $todo->income_left !== '');
                $hasPayments = ($todo->total_payment !== null && $todo->total_payment !== '')
                    || ($todo->payment_left !== null && $todo->payment_left !== '');

                return [
                    'day' => $day,
                    'status' => $todo->status?->value ?? null,
                    'priority' => $todo->priority?->value ?? 'regular',
                    'total_payment' => (float) ($todo->total_payment ?? 0),
                    'payment_left' => $todo->payment_left !== null ? (float) $todo->payment_left : null,
                    'total_income' => (float) ($todo->total_income ?? 0),
                    'income_left' => $todo->income_left !== null ? (float) $todo->income_left : null,
                    'has_financials' => $hasIncome || $hasPayments,
                    'has_income' => $hasIncome,
                    'has_payments' => $hasPayments,
                    'has_income_left' => $todo->income_left !== null && $todo->income_left !== '',
                    'has_payment_left' => $todo->payment_left !== null && $todo->payment_left !== '',
                    'user_id' => $todo->user_id,
                    'user_label' => $todo->user?->name ?? ('User #'.$todo->user_id),
                ];
            })
            ->values()
            ->all();
    }
}
