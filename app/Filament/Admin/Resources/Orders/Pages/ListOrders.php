<?php

namespace App\Filament\Admin\Resources\Orders\Pages;

use App\Filament\Admin\Concerns\HasDoubleClickCellEdit;
use App\Filament\Admin\Resources\Orders\OrderResource;
use App\Models\Contact;
use App\Models\Order;
use App\Support\Money;
use Filament\Actions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\RenderHook;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\View\PanelsRenderHook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Validation\ValidationException;

class ListOrders extends ListRecords
{
    use HasDoubleClickCellEdit;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\CreateAction::make(),
        ];
    }

    protected function doubleClickCellEditRecord(string|int $key): ?Model
    {
        return Order::query()->with('user')->find($key);
    }

    /**
     * @return array<string, array{label: string, form: mixed, fill: \Closure, save: \Closure, can?: \Closure, description?: mixed}>
     */
    protected function doubleClickCellEditFields(): array
    {
        $fields = [
            'customer_name' => [
                'label' => 'Customer name',
                'description' => 'Updates this customer’s company name everywhere it appears.',
                'can' => fn (Order $record): bool => $record->user !== null,
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Customer name')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (Order $record): ?string => $record->user?->company_name,
                'save' => function (Order $record, mixed $value): void {
                    $user = $record->user;
                    if ($user === null) {
                        throw ValidationException::withMessages(['value' => 'This order has no customer.']);
                    }

                    $name = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $user->update(['company_name' => $name]);
                },
            ],
        ];

        if (SchemaFacade::hasColumn('orders', 'order_date')) {
            $fields['order_date'] = [
                'label' => 'Order date',
                'form' => fn (): DatePicker => DatePicker::make('value')
                    ->label('Order date')
                    ->native(false)
                    ->required(),
                'fill' => fn (Order $record): ?string => $record->order_date?->toDateString(),
                'save' => function (Order $record, mixed $value): void {
                    $date = (string) $this->doubleClickValidate($value, ['required', 'date']);
                    $record->update(['order_date' => $date]);
                },
            ];
        }

        return $fields;
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getTabsContentComponent(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE),
                EmbeddedTable::make(),
                RenderHook::make(PanelsRenderHook::RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER),
                View::make('filament.admin.resources.orders.period-summary'),
            ]);
    }

    /**
     * @return array{
     *     description: string,
     *     footer: string,
     *     stats: list<array{label: string, value: string, hint: string, emphasis?: bool}>
     * }
     */
    public function periodSummary(): array
    {
        $query = $this->getFilteredTableQuery() ?? Order::query();
        $rows = (clone $query)->with('orderItems')->get();

        $orders = $rows->count();
        $items = (int) $rows->sum(fn (Order $order): int => (int) $order->orderItems->sum('amount'));
        $shipping = (float) $rows->sum(fn (Order $order): float => (float) $order->shipping_cost);
        $total = (float) $rows->sum(fn (Order $order): float => (float) $order->amount);
        $avgOrder = $orders > 0 ? $total / $orders : 0.0;

        return [
            'description' => $this->periodSummaryDescription(),
            'footer' => $orders.' order(s) included in this report.',
            'stats' => $orders === 0 ? [] : [
                [
                    'label' => 'Orders',
                    'value' => number_format($orders),
                    'hint' => 'Matching orders',
                ],
                [
                    'label' => 'Line items',
                    'value' => number_format($items),
                    'hint' => 'Total quantity',
                ],
                [
                    'label' => 'Shipping',
                    'value' => Money::format($shipping),
                    'hint' => 'Delivery charges',
                ],
                [
                    'label' => 'Revenue',
                    'value' => Money::format($total),
                    'hint' => 'Avg '.Money::format($avgOrder).' per order',
                    'emphasis' => true,
                ],
            ],
        ];
    }

    private function periodSummaryDescription(): string
    {
        $parts = [$this->periodSummaryDateLabel()];

        $market = $this->tableFilterValue('customer_market');
        $parts[] = match ($market) {
            'lithuania' => 'Lithuania customers',
            'export' => 'Export customers',
            default => 'All customers',
        };

        $contactId = $this->tableFilterValue('contact_id');
        if (filled($contactId)) {
            $name = Contact::query()->whereKey($contactId)->value('company_name');
            if (filled($name)) {
                $parts[] = (string) $name;
            }
        }

        return implode(' · ', $parts);
    }

    private function periodSummaryDateLabel(): string
    {
        $month = $this->tableFilterValue('month');
        if (is_string($month) && preg_match('/^\d{4}-\d{2}$/', $month) === 1) {
            $start = Carbon::createFromFormat('Y-m', $month)->startOfMonth();

            return $start->toDateString().' — '.$start->copy()->endOfMonth()->toDateString();
        }

        $from = data_get($this->tableFilters, 'invoice_dates.from');
        $until = data_get($this->tableFilters, 'invoice_dates.until');

        if (filled($from) && filled($until)) {
            return $from.' — '.$until;
        }

        if (filled($from)) {
            return 'From '.$from;
        }

        if (filled($until)) {
            return 'Until '.$until;
        }

        return 'All dates';
    }

    private function tableFilterValue(string $filter): mixed
    {
        $value = data_get($this->tableFilters, $filter.'.value');

        if ($value !== null) {
            return $value;
        }

        return data_get($this->tableFilters, $filter);
    }
}
