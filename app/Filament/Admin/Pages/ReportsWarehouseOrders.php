<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Models\Cargo;
use App\Models\Supplier;
use App\Services\ActivityLogger;
use App\Services\CargoCostCalculator;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action as TableAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;
use UnitEnum;

class ReportsWarehouseOrders extends Page implements HasForms, HasTable
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Warehouse orders report';

    protected static ?string $title = 'Warehouse orders report';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

    protected static ?int $navigationSort = 15;

    protected string $view = 'filament.admin.pages.reports-warehouse-orders';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            ...$this->defaultReportMonthRangeFill(),
            'supplier_id' => 'all',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('supplier_id')
                    ->label('Supplier')
                    ->options(fn (): array => ['all' => 'All'] + Supplier::query()
                        ->orderBy('name')
                        ->pluck('name', 'id')
                        ->all())
                    ->default('all')
                    ->native(false)
                    ->live(),
            ])
            ->columns(4);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->cargosQuery())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('date_shipped')
                    ->label('Shipped')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items')
                    ->label('Items')
                    ->getStateUsing(fn (Cargo $record): int => (int) $record->cargoItems->sum('amount')),

                Tables\Columns\TextColumn::make('shipping_cost')
                    ->label('Shipping')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('full_cost')
                    ->label('Full cost')
                    ->getStateUsing(fn (Cargo $record): float => CargoCostCalculator::fullCostWithShipping($record))
                    ->money(Money::currency()),
            ])
            ->headerActions([
                TableAction::make('export_csv')
                    ->label('Export CSV')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
                TableAction::make('export_excel')
                    ->label('Export Excel')
                    ->icon('heroicon-o-document-arrow-down')
                    ->action(fn (): StreamedResponse => $this->exportExcel()),
            ])
            ->defaultSort('id', 'desc')
            ->paginated([25, 50, 100]);
    }

    private function cargosQuery(): Builder
    {
        $query = Cargo::query()->with([
            'supplier',
            'cargoItems.product.productType',
            'importTax',
        ]);

        $this->applyEffectiveDateFilters($query, $this->data['from'] ?? null, $this->data['until'] ?? null);

        $supplierId = $this->data['supplier_id'] ?? 'all';
        if ($supplierId !== 'all' && filled($supplierId)) {
            $query->where('supplier_id', (int) $supplierId);
        }

        return $query;
    }

    private function applyEffectiveDateFilters(Builder $query, ?string $from, ?string $until): void
    {
        if (filled($from)) {
            $query->whereRaw('COALESCE(DATE(date_shipped), DATE(created_at)) >= ?', [$from]);
        }

        if (filled($until)) {
            $query->whereRaw('COALESCE(DATE(date_shipped), DATE(created_at)) <= ?', [$until]);
        }
    }

    /**
     * @return array{orders:int,items:int,shipping:float,total:float}
     */
    public function totals(): array
    {
        $rows = $this->cargosQuery()->get();

        return [
            'orders' => $rows->count(),
            'items' => (int) $rows->sum(fn (Cargo $cargo): int => (int) $cargo->cargoItems->sum('amount')),
            'shipping' => (float) $rows->sum(fn (Cargo $cargo): float => (float) $cargo->shipping_cost),
            'total' => (float) $rows->sum(fn (Cargo $cargo): float => CargoCostCalculator::fullCostWithShipping($cargo)),
        ];
    }

    private function supplierLabel(Cargo $cargo): string
    {
        return (string) ($cargo->supplier?->name ?? '—');
    }

    private function exportCsv(): StreamedResponse
    {
        $fileName = 'warehouse-orders-report-'.now()->format('Ymd-His').'.csv';
        $rows = $this->cargosQuery()->orderBy('date_shipped')->orderBy('id')->get();

        ActivityLogger::logReportDownloaded('Warehouse orders report CSV', 'csv', properties: [
            'file_name' => $fileName,
            'count' => $rows->count(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Cargo #', 'Shipped', 'Supplier', 'Status', 'Items', 'Shipping', 'Full cost']);

            foreach ($rows as $cargo) {
                fputcsv($out, [
                    $cargo->id,
                    optional($cargo->date_shipped)->format('Y-m-d'),
                    $this->supplierLabel($cargo),
                    (string) ($cargo->status?->value ?? ''),
                    (int) $cargo->cargoItems->sum('amount'),
                    number_format((float) $cargo->shipping_cost, 2, '.', ''),
                    number_format(CargoCostCalculator::fullCostWithShipping($cargo), 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportExcel(): StreamedResponse
    {
        $fileName = 'warehouse-orders-report-'.now()->format('Ymd-His').'.xls';
        $rows = $this->cargosQuery()->orderBy('date_shipped')->orderBy('id')->get();

        ActivityLogger::logReportDownloaded('Warehouse orders report XLS', 'xls', properties: [
            'file_name' => $fileName,
            'count' => $rows->count(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            echo '<table border="1"><tr>';
            foreach (['Cargo #', 'Shipped', 'Supplier', 'Status', 'Items', 'Shipping', 'Full cost'] as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            foreach ($rows as $cargo) {
                echo '<tr>';
                echo '<td>'.e((string) $cargo->id).'</td>';
                echo '<td>'.e((string) optional($cargo->date_shipped)->format('Y-m-d')).'</td>';
                echo '<td>'.e($this->supplierLabel($cargo)).'</td>';
                echo '<td>'.e((string) ($cargo->status?->value ?? '')).'</td>';
                echo '<td>'.e((string) (int) $cargo->cargoItems->sum('amount')).'</td>';
                echo '<td>'.e(number_format((float) $cargo->shipping_cost, 2, '.', '')).'</td>';
                echo '<td>'.e(number_format(CargoCostCalculator::fullCostWithShipping($cargo), 2, '.', '')).'</td>';
                echo '</tr>';
            }

            echo '</table>';
        }, $fileName, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }
}
