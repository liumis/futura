<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Concerns\HasReportMonthQuickFilter;
use App\Filament\Admin\Resources\Documents\DocumentResource;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Order;
use App\Models\Package;
use App\Services\ActivityLogger;
use App\Services\OrderPackageCalculator;
use App\Services\PackingReportDocumentGenerator;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Action as TableAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class ReportsPacking extends Page implements HasForms, HasTable
{
    use HasReportMonthQuickFilter;
    use InteractsWithForms;
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cube';

    protected static ?string $navigationLabel = 'Packing reports';

    protected static string|UnitEnum|null $navigationGroup = 'Reports';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.admin.pages.reports-packing';

    /**
     * @var array<string, mixed>|null
     */
    public ?array $data = [];

    public function mount(): void
    {
        $this->form->fill([
            ...$this->defaultReportMonthRangeFill(),
            'package_id' => 'all',
            'export' => 'all',
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                ...$this->reportMonthRangeFilters(),

                Select::make('package_id')
                    ->label('Package profile')
                    ->options(fn (): array => ['all' => 'All packages'] + $this->packageOptions())
                    ->native(false)
                    ->searchable()
                    ->live(),

                Select::make('export')
                    ->label('Customer type')
                    ->options([
                        'all' => 'All',
                        PackingReportDocumentGenerator::AUDIENCE_INTERNAL => 'Internal',
                        PackingReportDocumentGenerator::AUDIENCE_EXPORT => 'Export',
                    ])
                    ->default('all')
                    ->native(false)
                    ->live(),
            ])
            ->columns(5);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->ordersQuery())
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Order #')
                    ->sortable()
                    ->url(fn (Order $record): string => OrderResource::getUrl('edit', ['record' => $record])),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Order date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('customer')
                    ->label('Customer')
                    ->getStateUsing(fn (Order $record): string => $record->user?->company_name ?? trim(($record->user?->name ?? '').' '.($record->user?->surname ?? '')))
                    ->searchable(['user.company_name', 'user.name', 'user.surname']),

                Tables\Columns\TextColumn::make('customer_type')
                    ->label('Type')
                    ->getStateUsing(fn (Order $record): string => $record->user?->export ? 'Export' : 'Internal'),

                Tables\Columns\TextColumn::make('package.name')
                    ->label('Package profile')
                    ->placeholder('Default')
                    ->sortable(),

                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Tracking')
                    ->placeholder('—')
                    ->searchable(),

                Tables\Columns\TextColumn::make('items')
                    ->label('Items')
                    ->getStateUsing(fn (Order $record): int => $this->packingStats($record)['items']),

                Tables\Columns\TextColumn::make('packages')
                    ->label('Packages')
                    ->getStateUsing(fn (Order $record): int => $this->packingStats($record)['packages']),

                Tables\Columns\TextColumn::make('palletes')
                    ->label('Palletes')
                    ->getStateUsing(fn (Order $record): int => $this->packingStats($record)['palletes']),

                Tables\Columns\TextColumn::make('netto')
                    ->label('Netto')
                    ->getStateUsing(fn (Order $record): string => number_format($this->packingStats($record)['netto'], 3).' kg'),

                Tables\Columns\TextColumn::make('brutto')
                    ->label('Brutto')
                    ->getStateUsing(fn (Order $record): string => number_format($this->packingStats($record)['brutto'], 3).' kg'),

                Tables\Columns\TextColumn::make('plastic')
                    ->label('Plastic')
                    ->getStateUsing(fn (Order $record): string => number_format($this->packingStats($record)['plastic'], 3).' kg'),

                Tables\Columns\TextColumn::make('cardboard_i')
                    ->label('Cardboard I')
                    ->getStateUsing(fn (Order $record): string => number_format($this->packingStats($record)['cardboard_i'], 3).' kg'),

                Tables\Columns\TextColumn::make('cardboard_ii')
                    ->label('Cardboard II')
                    ->getStateUsing(fn (Order $record): string => number_format($this->packingStats($record)['cardboard_ii'], 3).' kg'),

                Tables\Columns\TextColumn::make('wood')
                    ->label('Wood')
                    ->getStateUsing(fn (Order $record): string => number_format($this->packingStats($record)['wood'], 3).' kg'),
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
            ->defaultSort('order_date', 'desc')
            ->paginated([25, 50, 100]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->generateInternalReportAction(),
            $this->generateExportReportAction(),
        ];
    }

    public function generateInternalReportAction(): Action
    {
        return $this->makeGenerateAction(
            'generateInternalReport',
            'Generate internal',
            PackingReportDocumentGenerator::AUDIENCE_INTERNAL,
        );
    }

    public function generateExportReportAction(): Action
    {
        return $this->makeGenerateAction(
            'generateExportReport',
            'Generate export',
            PackingReportDocumentGenerator::AUDIENCE_EXPORT,
        );
    }

    private function makeGenerateAction(string $name, string $label, string $audience): Action
    {
        $audienceLabel = PackingReportDocumentGenerator::audienceLabel($audience);

        return Action::make($name)
            ->label($label)
            ->icon('heroicon-o-document-arrow-down')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading('Generate '.$audienceLabel.' packing report')
            ->modalDescription(function () use ($audience, $audienceLabel): string {
                $filters = $this->filterContext($audience);
                $count = $this->ordersQuery($audience)->count();

                return sprintf(
                    'Save a %s packing PDF (%s · %s · %s order(s)) to Documents and SharePoint?',
                    $audienceLabel,
                    $filters['period_label'],
                    $filters['package_label'],
                    number_format($count),
                );
            })
            ->modalSubmitActionLabel('Generate')
            ->action(function () use ($audience): void {
                $this->generateReport($audience);
            });
    }

    public function generateReport(string $audience): void
    {
        try {
            $document = PackingReportDocumentGenerator::create(
                $this->reportOrders($audience),
                $this->filterContext($audience),
                auth()->user(),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not generate report')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        $audienceLabel = PackingReportDocumentGenerator::audienceLabel($audience);

        Notification::make()
            ->title($audienceLabel.' packing report saved')
            ->body('The PDF was stored in Documents and uploaded to SharePoint.')
            ->success()
            ->actions([
                Action::make('openDocument')
                    ->label('Open document')
                    ->url(DocumentResource::getUrl('edit', ['record' => $document]))
                    ->openUrlInNewTab(),
            ])
            ->send();
    }

    /**
     * @return array{
     *     from: ?string,
     *     until: ?string,
     *     package_label: string,
     *     period_label: string,
     *     audience: string,
     *     audience_label: string
     * }
     */
    public function filterContext(?string $audience = null): array
    {
        $from = $this->dateLabel($this->data['from'] ?? null);
        $until = $this->dateLabel($this->data['until'] ?? null);
        $audience = PackingReportDocumentGenerator::normalizeAudience(
            $audience ?? ($this->data['export'] ?? 'all'),
        );
        $packageId = $this->data['package_id'] ?? 'all';
        $packageLabel = $packageId === 'all' || ! filled($packageId)
            ? 'All package profiles'
            : (Package::query()->find($packageId)?->name ?? 'Selected package profile');

        if (filled($from) && filled($until)) {
            $periodLabel = $from.' — '.$until;
        } elseif (filled($from)) {
            $periodLabel = 'From '.$from;
        } elseif (filled($until)) {
            $periodLabel = 'Until '.$until;
        } else {
            $periodLabel = 'All dates';
        }

        return [
            'from' => $from,
            'until' => $until,
            'package_label' => $packageLabel,
            'period_label' => $periodLabel,
            'audience' => $audience,
            'audience_label' => PackingReportDocumentGenerator::audienceLabel($audience),
        ];
    }

    private function dateLabel(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (Throwable) {
            return trim((string) $value) ?: null;
        }
    }

    /**
     * @return array<string, string>
     */
    private function packageOptions(): array
    {
        return Package::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return Collection<int, Order>
     */
    private function reportOrders(string $audience): Collection
    {
        return $this->ordersQuery($audience)->orderBy('order_date')->orderBy('id')->get();
    }

    private function ordersQuery(?string $audience = null): Builder
    {
        $query = Order::query()->with(['user', 'orderItems', 'package']);

        $from = $this->dateLabel($this->data['from'] ?? null);
        $until = $this->dateLabel($this->data['until'] ?? null);

        if (filled($from)) {
            $query->whereRaw('COALESCE(DATE(order_date), DATE(created_at)) >= ?', [$from]);
        }

        if (filled($until)) {
            $query->whereRaw('COALESCE(DATE(order_date), DATE(created_at)) <= ?', [$until]);
        }

        $packageId = $this->data['package_id'] ?? 'all';
        if ($packageId !== 'all' && filled($packageId)) {
            $query->where('package_id', (int) $packageId);
        }

        $audience = PackingReportDocumentGenerator::normalizeAudience(
            $audience ?? ($this->data['export'] ?? 'all'),
        );
        if ($audience === PackingReportDocumentGenerator::AUDIENCE_EXPORT) {
            $query->whereHas('user', fn (Builder $q) => $q->where('export', true));
        } elseif ($audience === PackingReportDocumentGenerator::AUDIENCE_INTERNAL) {
            $query->whereHas('user', fn (Builder $q) => $q->where('export', false));
        }

        return $query;
    }

    /**
     * @return array{
     *     items: int,
     *     packages: int,
     *     palletes: int,
     *     netto: float,
     *     brutto: float,
     *     plastic: float,
     *     cardboard_i: float,
     *     cardboard_ii: float,
     *     wood: float
     * }
     */
    private function packingStats(Order $order): array
    {
        return OrderPackageCalculator::calculate($order);
    }

    /**
     * @return array{
     *     orders: int,
     *     items: int,
     *     packages: int,
     *     palletes: int,
     *     netto: float,
     *     brutto: float,
     *     plastic: float,
     *     cardboard_i: float,
     *     cardboard_ii: float,
     *     wood: float
     * }
     */
    public function totals(): array
    {
        $totals = [
            'orders' => 0,
            'items' => 0,
            'packages' => 0,
            'palletes' => 0,
            'netto' => 0.0,
            'brutto' => 0.0,
            'plastic' => 0.0,
            'cardboard_i' => 0.0,
            'cardboard_ii' => 0.0,
            'wood' => 0.0,
        ];

        foreach ($this->ordersQuery()->get() as $order) {
            $stats = $this->packingStats($order);
            $totals['orders']++;
            $totals['items'] += $stats['items'];
            $totals['packages'] += $stats['packages'];
            $totals['palletes'] += $stats['palletes'];
            $totals['netto'] += $stats['netto'];
            $totals['brutto'] += $stats['brutto'];
            $totals['plastic'] += $stats['plastic'];
            $totals['cardboard_i'] += $stats['cardboard_i'];
            $totals['cardboard_ii'] += $stats['cardboard_ii'];
            $totals['wood'] += $stats['wood'];
        }

        return $totals;
    }

    private function exportCsv(): StreamedResponse
    {
        $fileName = 'packing-report-'.now()->format('Ymd-His').'.csv';
        $rows = $this->ordersQuery()->orderBy('order_date')->get();

        ActivityLogger::logReportDownloaded('Packing report CSV', 'csv', properties: [
            'file_name' => $fileName,
            'count' => $rows->count(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');

            fputcsv($out, [
                'Order #',
                'Order date',
                'Customer',
                'Type',
                'Package profile',
                'Tracking',
                'Items',
                'Packages',
                'Palletes',
                'Netto (kg)',
                'Brutto (kg)',
                'Plastic (kg)',
                'Cardboard I (kg)',
                'Cardboard II (kg)',
                'Wood (kg)',
            ]);

            foreach ($rows as $order) {
                $stats = $this->packingStats($order);

                fputcsv($out, [
                    $order->id,
                    optional($order->order_date)->format('Y-m-d H:i:s'),
                    $order->user?->company_name ?? trim(($order->user?->name ?? '').' '.($order->user?->surname ?? '')),
                    $order->user?->export ? 'Export' : 'Internal',
                    $order->package?->name ?? 'Default',
                    $order->tracking_number,
                    $stats['items'],
                    $stats['packages'],
                    $stats['palletes'],
                    number_format($stats['netto'], 3, '.', ''),
                    number_format($stats['brutto'], 3, '.', ''),
                    number_format($stats['plastic'], 3, '.', ''),
                    number_format($stats['cardboard_i'], 3, '.', ''),
                    number_format($stats['cardboard_ii'], 3, '.', ''),
                    number_format($stats['wood'], 3, '.', ''),
                ]);
            }

            fclose($out);
        }, $fileName, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function exportExcel(): StreamedResponse
    {
        $fileName = 'packing-report-'.now()->format('Ymd-His').'.xls';
        $rows = $this->ordersQuery()->orderBy('order_date')->get();

        ActivityLogger::logReportDownloaded('Packing report XLS', 'xls', properties: [
            'file_name' => $fileName,
            'count' => $rows->count(),
        ]);

        return response()->streamDownload(function () use ($rows): void {
            $headers = [
                'Order #',
                'Order date',
                'Customer',
                'Type',
                'Package profile',
                'Tracking',
                'Items',
                'Packages',
                'Palletes',
                'Netto (kg)',
                'Brutto (kg)',
                'Plastic (kg)',
                'Cardboard I (kg)',
                'Cardboard II (kg)',
                'Wood (kg)',
            ];

            echo '<table border="1"><tr>';
            foreach ($headers as $header) {
                echo '<th>'.e($header).'</th>';
            }
            echo '</tr>';

            foreach ($rows as $order) {
                $stats = $this->packingStats($order);

                echo '<tr>';
                echo '<td>'.e((string) $order->id).'</td>';
                echo '<td>'.e((string) optional($order->order_date)->format('Y-m-d H:i:s')).'</td>';
                echo '<td>'.e($order->user?->company_name ?? trim(($order->user?->name ?? '').' '.($order->user?->surname ?? ''))).'</td>';
                echo '<td>'.e($order->user?->export ? 'Export' : 'Internal').'</td>';
                echo '<td>'.e($order->package?->name ?? 'Default').'</td>';
                echo '<td>'.e((string) ($order->tracking_number ?? '')).'</td>';
                echo '<td>'.e((string) $stats['items']).'</td>';
                echo '<td>'.e((string) $stats['packages']).'</td>';
                echo '<td>'.e((string) $stats['palletes']).'</td>';
                echo '<td>'.e(number_format($stats['netto'], 3, '.', '')).'</td>';
                echo '<td>'.e(number_format($stats['brutto'], 3, '.', '')).'</td>';
                echo '<td>'.e(number_format($stats['plastic'], 3, '.', '')).'</td>';
                echo '<td>'.e(number_format($stats['cardboard_i'], 3, '.', '')).'</td>';
                echo '<td>'.e(number_format($stats['cardboard_ii'], 3, '.', '')).'</td>';
                echo '<td>'.e(number_format($stats['wood'], 3, '.', '')).'</td>';
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
