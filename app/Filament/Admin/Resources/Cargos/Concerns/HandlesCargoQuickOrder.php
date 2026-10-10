<?php

namespace App\Filament\Admin\Resources\Cargos\Concerns;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\CargoResource;
use App\Models\CargoItem;
use App\Models\Color;
use App\Models\Product;
use App\Services\CargoQuickOrderParser;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Actions as SchemaActions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View as ViewContract;
use Livewire\Attributes\Renderless;

trait HandlesCargoQuickOrder
{
    /**
     * @var array{raw_text: string, lines: list<array<string, mixed>>, summary: string}
     */
    public array $quickOrderDraft = [
        'raw_text' => '',
        'lines' => [],
        'summary' => '',
    ];

    public bool $orderCartOpen = false;

    public function quickOrderAction(): Action
    {
        return Action::make('quickOrder')
            ->label('Quick order')
            ->icon('heroicon-o-bolt')
            ->color('gray')
            ->modalHeading('Quick order')
            ->modalDescription('Paste plain text from an email. Map collection/color codes and amounts, edit, then add to this warehouse order.')
            ->modalWidth('5xl')
            ->schema([
                Grid::make(2)
                    ->schema([
                        Group::make([
                            Forms\Components\Textarea::make('raw_text')
                                ->label('Paste order text')
                                ->placeholder("agnona\n101 - 10\nArctic - 20\n\nAGNONA102ARCTIC30 — 3 vnt")
                                ->rows(14)
                                ->required()
                                ->columnSpanFull(),

                            SchemaActions::make([
                                Action::make('processQuickOrderText')
                                    ->label('Process text')
                                    ->color('primary')
                                    ->action(function (Get $get, Set $set): void {
                                        $supplierId = $this->quickOrderSupplierId();
                                        $rawText = (string) ($get('raw_text') ?? '');
                                        $lines = app(CargoQuickOrderParser::class)->parse(
                                            $rawText,
                                            $supplierId,
                                        );
                                        $summary = app(CargoQuickOrderParser::class)->summary($lines);
                                        $set('lines', $lines);
                                        $set('summary', $summary);
                                        $this->rememberQuickOrderDraft($rawText, $lines, $summary);

                                        $matched = collect($lines)->where('matched', true)->count();

                                        Notification::make()
                                            ->title($matched > 0
                                                ? "Recognized {$matched} item(s)"
                                                : 'No products matched')
                                            ->body($matched === 0
                                                ? 'Check the codes or select a supplier first.'
                                                : null)
                                            ->{$matched > 0 ? 'success' : 'warning'}()
                                            ->send();
                                    }),
                            ]),
                        ])->columnSpan(1),

                        Group::make([
                            Forms\Components\Repeater::make('lines')
                                ->label('Recognized items')
                                ->schema([
                                    Forms\Components\Hidden::make('product_id'),
                                    Forms\Components\Hidden::make('matched'),
                                    Forms\Components\Hidden::make('unit'),
                                    Forms\Components\Hidden::make('stock'),
                                    Forms\Components\Hidden::make('size_meters'),
                                    Forms\Components\Hidden::make('detail'),
                                    Forms\Components\TextInput::make('code')
                                        ->label('Code')
                                        ->disabled()
                                        ->dehydrated(),
                                    Forms\Components\TextInput::make('label')
                                        ->label('Product')
                                        ->disabled()
                                        ->dehydrated(),
                                    Forms\Components\TextInput::make('amount')
                                        ->label('Qty')
                                        ->numeric()
                                        ->minValue(0)
                                        ->required()
                                        ->live(debounce: 300)
                                        ->afterStateUpdated(function ($state, Get $get, Set $set): void {
                                            $amount = (int) $state;
                                            $size = $get('size_meters');
                                            $stock = $get('stock');
                                            $matched = (bool) $get('matched');

                                            if (! $matched) {
                                                $set('detail', 'Not matched');

                                                return;
                                            }

                                            if ($size) {
                                                $meters = $amount * (float) $size;
                                                $set('detail', sprintf(
                                                    '%d vnt × %s m = %s m · stock %s',
                                                    $amount,
                                                    rtrim(rtrim(number_format((float) $size, 2, '.', ''), '0'), '.'),
                                                    rtrim(rtrim(number_format($meters, 2, '.', ''), '0'), '.'),
                                                    $stock === null ? '—' : $stock,
                                                ));
                                            } else {
                                                $set('detail', sprintf(
                                                    '%d vnt · stock %s',
                                                    $amount,
                                                    $stock === null ? '—' : $stock,
                                                ));
                                            }
                                        }),
                                    Forms\Components\Placeholder::make('detail_view')
                                        ->label('Details')
                                        ->content(fn (Get $get): string => (string) ($get('detail') ?? '')),
                                ])
                                ->columns(3)
                                ->addable(false)
                                ->deletable()
                                ->reorderable(false)
                                ->default([])
                                ->live()
                                ->afterStateUpdated(function (?array $state, Get $get, Set $set): void {
                                    $summary = app(CargoQuickOrderParser::class)->summary($state ?? []);
                                    $set('summary', $summary);
                                    $this->rememberQuickOrderDraft(
                                        (string) ($get('raw_text') ?? ''),
                                        $state ?? [],
                                        $summary,
                                    );
                                })
                                ->itemLabel(fn (array $state): ?string => ($state['code'] ?? null).' · '.($state['amount'] ?? 0))
                                ->columnSpanFull(),

                            Forms\Components\Textarea::make('summary')
                                ->label('Order summary')
                                ->rows(6)
                                ->disabled()
                                ->dehydrated(false)
                                ->columnSpanFull(),
                        ])->columnSpan(1),
                    ]),
            ])
            ->fillForm(fn (): array => [
                'raw_text' => (string) ($this->quickOrderDraft['raw_text'] ?? ''),
                'lines' => is_array($this->quickOrderDraft['lines'] ?? null)
                    ? $this->quickOrderDraft['lines']
                    : [],
                'summary' => (string) ($this->quickOrderDraft['summary'] ?? ''),
            ])
            ->modalSubmitActionLabel('Add to order')
            ->action(function (array $data): void {
                $lines = is_array($data['lines'] ?? null) ? $data['lines'] : [];
                $rawText = (string) ($data['raw_text'] ?? '');
                $summary = app(CargoQuickOrderParser::class)->summary($lines);
                $this->rememberQuickOrderDraft($rawText, $lines, $summary);
                $this->applyQuickOrderLines($lines);
                $this->orderCartOpen = $this->orderCartCount() > 0;
            });
    }

    public function autoOrderAction(): Action
    {
        return Action::make('autoOrder')
            ->label('Auto order')
            ->icon('heroicon-o-sparkles')
            ->color('primary')
            ->tooltip('Order default amounts for products below recommended stock')
            ->action(fn (): mixed => $this->applyAutoOrder());
    }

    public function expandOrderCartAction(): Action
    {
        return Action::make('expandOrderCart')
            ->label('Expand cart')
            ->modalHeading('Order cart')
            ->modalDescription(fn (): string => $this->orderCartCount().' item(s) on this warehouse order')
            ->modalWidth('7xl')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            // Keep in header action cache for mountAction(), but don't show a header button.
            ->extraAttributes([
                'class' => 'hidden',
                'style' => 'display:none !important',
            ])
            ->modalContent(fn (): ViewContract => view(
                'filament.admin.components.cargo-order-cart-expanded',
                ['items' => $this->orderCartExpandedItems],
            ));
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function rememberQuickOrderDraft(string $rawText, array $lines, string $summary): void
    {
        $this->quickOrderDraft = [
            'raw_text' => $rawText,
            'lines' => array_values($lines),
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     */
    protected function applyQuickOrderLines(array $lines): void
    {
        $amounts = is_array($this->data['cargo_amounts'] ?? null)
            ? $this->data['cargo_amounts']
            : [];

        $added = 0;
        $skipped = 0;
        $updated = [];

        foreach ($lines as $line) {
            $productId = (int) ($line['product_id'] ?? 0);
            $amount = (int) ($line['amount'] ?? 0);

            if ($productId <= 0 || $amount <= 0 || ! ($line['matched'] ?? false)) {
                $skipped++;

                continue;
            }

            $key = (string) $productId;
            // Replace so re-applying an edited quick order updates qty instead of stacking.
            $amounts[$key] = $amount;
            $updated[] = [
                'productId' => $productId,
                'amount' => $amount,
            ];
            $added++;
        }

        $this->data['cargo_amounts'] = $amounts;

        Notification::make()
            ->title($added > 0 ? 'Quick order applied' : 'Nothing applied')
            ->body(sprintf('Updated %d line(s)%s.', $added, $skipped > 0 ? ", skipped {$skipped}" : ''))
            ->{$added > 0 ? 'success' : 'warning'}()
            ->send();

        if ($updated !== []) {
            $this->dispatch('cargo-amounts-batch-synced', items: $updated);
        }

        $this->dispatch('cargo-cost-recalculate');
    }

    protected function quickOrderSupplierId(): ?int
    {
        $supplierId = $this->data['supplier_id'] ?? null;

        if (blank($supplierId)) {
            $supplierId = $this->form->getRawState()['supplier_id'] ?? null;
        }

        return filled($supplierId) ? (int) $supplierId : null;
    }

    public function toggleOrderCart(): void
    {
        $this->orderCartOpen = ! $this->orderCartOpen;
    }

    #[Renderless]
    public function onCargoAmountInputUpdated(int $productId, int $amount): void
    {
        $amounts = is_array($this->data['cargo_amounts'] ?? null)
            ? $this->data['cargo_amounts']
            : [];
        $amounts[(string) $productId] = max(0, $amount);
        $this->data['cargo_amounts'] = $amounts;

        if ($this->orderCartCount() > 0) {
            $this->orderCartOpen = true;
        } elseif ($amount <= 0) {
            // keep panel state; badge will show 0 items
        }

        $this->dispatch('cargo-amount-synced', productId: $productId, amount: max(0, $amount));
        $this->dispatch('cargo-cost-recalculate');
    }

    public function updateColorRecommendedStock(int $colorId, mixed $value = null): void
    {
        if ($colorId <= 0) {
            return;
        }

        $recommended = max(0, (int) $value);

        Color::query()->whereKey($colorId)->update([
            'recommended_stock' => $recommended,
        ]);

        $this->dispatch('cargo-recommended-stock-updated', colorId: $colorId, amount: $recommended);
    }

    /**
     * Order default amounts for every product whose stock is below recommended stock.
     *
     * Renderless: updating hundreds of amount fields must not re-render the full
     * warehouse-order form (multi-MB HTML). Inputs are synced via a single JS event.
     */
    #[Renderless]
    public function applyAutoOrder(): void
    {
        $supplierId = $this->quickOrderSupplierId();

        if ($supplierId === null) {
            Notification::make()
                ->title('Select a supplier first')
                ->warning()
                ->send();

            return;
        }

        $defaults = CargoResource::lowStockDefaultOrderAmounts($supplierId);

        $amounts = is_array($this->data['cargo_amounts'] ?? null)
            ? $this->data['cargo_amounts']
            : [];

        $updated = [];

        foreach ($defaults as $productId => $defaultOrder) {
            $key = (string) $productId;
            $amounts[$key] = $defaultOrder;
            $updated[] = [
                'productId' => (int) $productId,
                'amount' => $defaultOrder,
            ];
        }

        $this->data['cargo_amounts'] = $amounts;

        $count = count($updated);

        Notification::make()
            ->title($count > 0 ? 'Auto order applied' : 'Nothing to auto-order')
            ->body($count > 0
                ? "Set default order on {$count} low-stock item(s)."
                : 'No products are below recommended stock for this supplier.')
            ->{$count > 0 ? 'success' : 'warning'}()
            ->send();

        if ($count > 0) {
            $this->orderCartOpen = true;
        }

        $this->dispatch('cargo-amounts-batch-synced', items: $updated);
        $this->dispatch('cargo-cost-recalculate');
    }

    #[Renderless]
    public function updateOrderCartAmount(int $productId, mixed $amount = null): void
    {
        $qty = max(0, (int) $amount);
        $amounts = is_array($this->data['cargo_amounts'] ?? null)
            ? $this->data['cargo_amounts']
            : [];
        $amounts[(string) $productId] = $qty;
        $this->data['cargo_amounts'] = $amounts;

        if ($qty === 0 && $this->orderCartCount() === 0) {
            $this->orderCartOpen = false;
        }

        $this->dispatch('cargo-amount-synced', productId: $productId, amount: $qty);
        $this->dispatch('cargo-cost-recalculate');
    }

    public function removeOrderCartItem(int $productId): void
    {
        $this->updateOrderCartAmount($productId, 0);
    }

    /**
     * Used from the expanded cart modal so the table re-renders after edits.
     */
    public function updateExpandedCartAmount(int $productId, mixed $amount = null): void
    {
        $this->updateOrderCartAmount($productId, $amount);

        if ($this->orderCartCount() === 0) {
            $this->unmountAction();
        }
    }

    public function removeExpandedCartItem(int $productId): void
    {
        $this->updateExpandedCartAmount($productId, 0);
    }

    #[Renderless]
    public function clearOrderCart(): void
    {
        $amounts = is_array($this->data['cargo_amounts'] ?? null)
            ? $this->data['cargo_amounts']
            : [];
        $cleared = [];

        foreach ($amounts as $productId => $amount) {
            if ((int) $amount > 0) {
                $amounts[$productId] = 0;
                $cleared[] = [
                    'productId' => (int) $productId,
                    'amount' => 0,
                ];
            }
        }

        $this->data['cargo_amounts'] = $amounts;
        $this->orderCartOpen = false;

        if ($cleared !== []) {
            $this->dispatch('cargo-amounts-batch-synced', items: $cleared);
        }

        $this->dispatch('cargo-cost-recalculate');
    }

    public function orderCartCount(): int
    {
        $amounts = is_array($this->data['cargo_amounts'] ?? null)
            ? $this->data['cargo_amounts']
            : [];

        return collect($amounts)
            ->filter(fn ($amount): bool => (int) $amount > 0)
            ->count();
    }

    /**
     * @return list<array{
     *     product_id: int,
     *     code: string,
     *     label: string,
     *     description: string,
     *     amount: int,
     *     size_meters: float|null,
     *     stock: int,
     *     stock_meters: float,
     *     recommended_meters: int,
     *     is_low_stock: bool,
     *     on_order_rolls: int,
     *     on_order_meters: float
     * }>
     */
    public function getOrderCartItemsProperty(): array
    {
        return $this->buildOrderCartItems();
    }

    /**
     * @return list<array{
     *     product_id: int,
     *     code: string,
     *     label: string,
     *     description: string,
     *     amount: int,
     *     size_meters: float|null,
     *     stock: int,
     *     stock_meters: float,
     *     recommended_meters: int,
     *     is_low_stock: bool,
     *     on_order_rolls: int,
     *     on_order_meters: float
     * }>
     */
    public function getOrderCartExpandedItemsProperty(): array
    {
        return $this->buildOrderCartItems();
    }

    /**
     * @return list<array{
     *     product_id: int,
     *     code: string,
     *     label: string,
     *     description: string,
     *     amount: int,
     *     size_meters: float|null,
     *     stock: int,
     *     stock_meters: float,
     *     recommended_meters: int,
     *     is_low_stock: bool,
     *     on_order_rolls: int,
     *     on_order_meters: float
     * }>
     */
    protected function buildOrderCartItems(): array
    {
        $amounts = is_array($this->data['cargo_amounts'] ?? null)
            ? $this->data['cargo_amounts']
            : [];

        $ids = collect($amounts)
            ->filter(fn ($amount): bool => (int) $amount > 0)
            ->keys()
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        $products = Product::query()
            ->with(['color.collection'])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $onOrderRolls = $this->upcomingOnOrderRollsByProductIds($ids);

        $items = [];

        foreach ($ids as $productId) {
            $product = $products->get($productId);
            if ($product === null) {
                continue;
            }

            $collection = $product->color?->collection?->name;
            $colorCode = trim((string) ($product->color?->color_code ?? ''));
            $colorName = trim((string) ($product->color?->color_name ?? ''));
            $color = trim(implode(' ', array_filter([$colorCode, $colorName])));
            $size = $product->sizeMeters();
            $stockMeters = $product->stockMeters();
            $recommended = (int) ($product->color?->recommended_stock ?? 0);
            $rollsOnOrder = (int) ($onOrderRolls[$productId] ?? 0);
            $onOrderMeters = $size !== null && $size > 0
                ? $rollsOnOrder * $size
                : (float) $rollsOnOrder;

            $items[] = [
                'product_id' => $productId,
                'code' => (string) ($product->alternative_code ?: $product->product_code ?: $productId),
                'label' => trim(implode(' · ', array_filter([
                    $collection,
                    $color !== '' ? $color : null,
                    $size ? $size.' m' : null,
                ]))),
                'description' => trim(implode(' ', array_filter([
                    $collection,
                    $colorCode !== '' && $colorName !== '' ? $colorCode.'-'.$colorName : ($color !== '' ? $color : null),
                ]))),
                'amount' => (int) ($amounts[(string) $productId] ?? $amounts[$productId] ?? 0),
                'size_meters' => $size,
                'stock' => (int) $product->current_amount,
                'stock_meters' => $stockMeters,
                'recommended_meters' => $recommended,
                'is_low_stock' => $recommended > 0 && $stockMeters < $recommended,
                'on_order_rolls' => $rollsOnOrder,
                'on_order_meters' => $onOrderMeters,
            ];
        }

        usort(
            $items,
            fn (array $left, array $right): int => strcasecmp($left['label'], $right['label']),
        );

        return $items;
    }

    /**
     * Rolls already on other warehouse orders that are not received yet.
     *
     * @param  list<int>  $productIds
     * @return array<int, int>
     */
    protected function upcomingOnOrderRollsByProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        $query = CargoItem::query()
            ->selectRaw('cargo_items.product_id as product_id, SUM(cargo_items.amount) as amount')
            ->join('cargos', 'cargos.id', '=', 'cargo_items.cargo_id')
            ->whereIn('cargo_items.product_id', $ids)
            ->where('cargos.status', '!=', CargoStatus::Received->value)
            ->groupBy('cargo_items.product_id');

        $currentCargoId = isset($this->record) && filled($this->record?->getKey())
            ? (int) $this->record->getKey()
            : null;

        if ($currentCargoId) {
            $query->where('cargos.id', '!=', $currentCargoId);
        }

        return $query
            ->get()
            ->mapWithKeys(fn ($row): array => [(int) $row->product_id => (int) $row->amount])
            ->all();
    }

    protected function cargoPageContent(Schema $schema): Schema
    {
        return $schema
            ->components([
                $this->getFormContentComponent(),
                View::make('filament.admin.components.cargo-order-cart'),
            ]);
    }
}
