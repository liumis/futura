<?php

namespace App\Filament\Admin\Concerns;

use App\Support\ReportDateRange;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;

trait HasReportMonthQuickFilter
{
    /**
     * Month select + From/Until date pickers (from/until remain the query source of truth).
     *
     * @return list<Select|DatePicker>
     */
    protected function reportMonthRangeFilters(): array
    {
        return [
            $this->reportMonthSelect(),
            $this->reportFromDatePicker(),
            $this->reportUntilDatePicker(),
        ];
    }

    protected function reportMonthSelect(): Select
    {
        return Select::make('month')
            ->label('Month')
            ->options(fn (): array => ReportDateRange::monthQuickSelectOptions())
            ->placeholder('Custom range')
            ->native(false)
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set): void {
                if (blank($state)) {
                    return;
                }

                $bounds = ReportDateRange::boundsForMonthKey($state);
                $set('from', $bounds['from']);
                $set('until', $bounds['until']);
            });
    }

    protected function reportFromDatePicker(): DatePicker
    {
        return DatePicker::make('from')
            ->label('From')
            ->native(false)
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set): void {
                $set('month', ReportDateRange::monthKeyFromDates($get('from'), $get('until')));
            });
    }

    protected function reportUntilDatePicker(): DatePicker
    {
        return DatePicker::make('until')
            ->label('Until')
            ->native(false)
            ->live()
            ->afterStateUpdated(function (Get $get, Set $set): void {
                $set('month', ReportDateRange::monthKeyFromDates($get('from'), $get('until')));
            });
    }

    /**
     * @return array{month: string, from: ?string, until: ?string}
     */
    protected function defaultReportMonthRangeFill(): array
    {
        return ReportDateRange::lastMonthFill();
    }
}
