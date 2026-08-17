<?php

namespace App\Filament\Admin\Pages;

use App\Models\Order;
use App\Services\ActivityLogger;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action as TableAction;
use Filament\Forms\Components\DatePicker;
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

class ReportsOrders extends Page implements HasForms, HasTable
{
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Orders';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 1;

    protected string $view = 'filament.admin.pages.reports-orders';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            'from' => now()->startOfMonth()->toDateString(),
            'until' => now()->endOfMonth()->toDateString(),
            'export' => 'all',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                DatePicker::make('from')
                    ->label('From')
                    ->native(false)
                    ->live(),

                DatePicker::make('until')
                    ->label('Until')
                    ->native(false)
                    ->live(),

                Select::make('export')
                    ->label('Export customer')
                    ->options([
                        'all' => 'All',
                        'yes' => 'Export only',
                        'no' => 'Non-export only',
                    ])
                    ->default('all')
                    ->native(false)
                    ->live(),
            ])
            ->columns(3);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->ordersQuery())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Order date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(fn (Order $record): string => $record->user?->company_name ?? trim(($record->user?->name ?? '').' '.($record->user?->surname ?? '')))
                    ->searchable(['user.company_name', 'user.name', 'user.surname']),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items')
                    ->label('Items')
                    ->getStateUsing(fn (Order $record): int => (int) $record->orderItems->sum('amount')),

                Tables\Columns\TextColumn::make('shipping_cost')
                    ->label('Shipping')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total')
                    ->money(Money::currency())
                    ->sortable(),
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

    private function ordersQuery(): Builder
    {
        $query = Order::query()->with(['user', 'orderItems']);

        $this->applyEffectiveDateFilters($query, $this->data['from'] ?? null, $this->data['until'] ?? null);

        $export = $this->data['export'] ?? 'all';
        if ($export === 'yes') {
            $query->whereHas('user', fn (Builder $q) => $q->where('export', true));
        } elseif ($export === 'no') {
            $query->whereHas('user', fn (Builder $q) => $q->where('export', false));
        }

        return $query;
    }

    private function applyEffectiveDateFilters(Builder $query, ?string $from, ?string $until): void
    {
        if (filled($from)) {
            $query->whereRaw('COALESCE(DATE(order_date), DATE(created_at)) >= ?', [$from]);
        }

        if (filled($until)) {
            $query->whereRaw('COALESCE(DATE(order_date), DATE(created_at)) <= ?', [$until]);
        }
    }

    /**
     * @return array{orders:int,items:int,shipping:float,total:float}
     */
    public function totals(): array
    {
        $rows = $this->ordersQuery()->get();

        return [
            'orders' => $rows->count(),
            'items' => (int) $rows->sum(fn (Order $order): int => (int) $order->orderItems->sum('amount')),
            'shipping' => (float) $rows->sum(fn (Order $order): float => (float) $order->shipping_cost),
            'total' => (float) $rows->sum(fn (Order $order): float => (float) $order->amount),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        $fileName = 'orders-report-'.now()->format('Ymd-His').'.csv';
        $rows = $this->ordersQuery()->orderBy('order_date')->get();

        ActivityLogger::logReportDownloaded('Orders report CSV', 'csv', properties: [
            'file_name' => $fileName,
            'count' => $rows->count(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, ['Order #', 'Order date', 'Customer', 'Status', 'Items', 'Shipping', 'Total']);

            foreach ($rows as $order) {
                fputcsv($out, [
                    $order->id,
                    optional($order->order_date)->format('Y-m-d H:i:s'),
                    $order->user?->company_name ?? trim(($order->user?->name ?? '').' '.($order->user?->surname ?? '')),
                    (string) $order->status?->value,
                    (int) $order->orderItems->sum('amount'),
                    number_format((float) $order->shipping_cost, 2, '.', ''),
                    number_format((float) $order->amount, 2, '.', ''),
                ]);
            }

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportExcel(): StreamedResponse
    {
        $fileName = 'orders-report-'.now()->format('Ymd-His').'.xls';
        $rows = $this->ordersQuery()->orderBy('order_date')->get();

        ActivityLogger::logReportDownloaded('Orders report XLS', 'xls', properties: [
            'file_name' => $fileName,
            'count' => $rows->count(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            echo '<table border="1"><tr>';
            foreach (['Order #', 'Order date', 'Customer', 'Status', 'Items', 'Shipping', 'Total'] as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            foreach ($rows as $order) {
                echo '<tr>';
                echo '<td>'.e((string) $order->id).'</td>';
                echo '<td>'.e((string) optional($order->order_date)->format('Y-m-d H:i:s')).'</td>';
                echo '<td>'.e($order->user?->company_name ?? trim(($order->user?->name ?? '').' '.($order->user?->surname ?? ''))).'</td>';
                echo '<td>'.e((string) $order->status?->value).'</td>';
                echo '<td>'.e((string) (int) $order->orderItems->sum('amount')).'</td>';
                echo '<td>'.e(number_format((float) $order->shipping_cost, 2, '.', '')).'</td>';
                echo '<td>'.e(number_format((float) $order->amount, 2, '.', '')).'</td>';
                echo '</tr>';
            }

            echo '</table>';
        }, $fileName, [
            'Content-Type' => 'application/vnd.ms-excel; charset=UTF-8',
        ]);
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
