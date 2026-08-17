<?php

namespace App\Filament\Admin\Resources\Orders;

use App\Enums\CargoStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Support\ProductLineItemCard;
use App\Models\CargoItem;
use App\Models\Color;
use App\Models\Collection as CollectionModel;
use App\Models\CustomerLevelPrice;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\FulfillmentOrderMailer;
use App\Services\OrderInvoiceCreator;
use App\Services\OrderShippedMailer;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Orders';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'Orders';

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('status')
                    ->options(OrderStatus::class)
                    ->required(fn (): bool => ! (auth()->user()?->hasRole('customer') ?? false))
                    ->native(false)
                    ->default(OrderStatus::Pending)
                    ->hidden(fn (): bool => auth()->user()?->hasRole('customer') ?? false),

                Forms\Components\Select::make('user_id')
                    ->label('Customer')
                    ->relationship(
                        'user',
                        'name',
                        modifyQueryUsing: fn (Builder $query) => $query->role('customer'),
                    )
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => self::formatCustomerOptionLabel($record))
                    ->searchable()
                    ->preload()
                    ->required(fn (): bool => ! (auth()->user()?->hasRole('customer') ?? false))
                    ->visible(fn (): bool => ! (auth()->user()?->hasRole('customer') ?? false)),

                Forms\Components\Hidden::make('user_id')
                    ->default(fn (): ?int => auth()->id())
                    ->visible(fn (): bool => auth()->user()?->hasRole('customer') ?? false),

                Forms\Components\TextInput::make('shipping_cost')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->label('Shipping cost')
                    ->readOnly(fn (): bool => auth()->user()?->hasRole('customer') ?? false),

                Forms\Components\TextInput::make('tracking_number')
                    ->maxLength(255)
                    ->label('Tracking number')
                    ->visible(fn (): bool => ! (auth()->user()?->hasRole('customer') ?? false)),

                Forms\Components\DateTimePicker::make('order_date')
                    ->label('Date')
                    ->default(fn (): string => now()->format('Y-m-d H:i:s'))
                    ->visible(fn (): bool => SchemaFacade::hasColumn('orders', 'order_date')),

                Forms\Components\Select::make('package_id')
                    ->label('Package profile')
                    ->options(fn (): array => self::packageProfileOptions())
                    ->default(fn (): ?int => self::defaultPackageProfileId())
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->visible(fn (): bool => self::packageProfileOptions() !== []),

                \Filament\Schemas\Components\View::make('filament.admin.components.order-package-summary')
                    ->viewData([
                        'packageProfiles' => self::packageProfiles(),
                        'initialPackageId' => self::defaultPackageProfileId(),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (): bool => self::packageProfiles() !== []),

                ...self::orderLineItemSections(),
            ]);
    }

    /**
     * @return array<int, array<string, float|int|string>>
     */
    private static function packageProfiles(): array
    {
        return Package::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Package $package): array => [
                (int) $package->id => [
                    'id' => (int) $package->id,
                    'name' => (string) $package->name,
                    'items_on_palette' => (int) ($package->items_on_palette ?? 0),
                    'total_weight' => (float) ($package->total_weight ?? 0),
                    'plastic_weight' => (float) ($package->plastic_weight ?? 0),
                    'cardboard_i_weight' => (float) ($package->cardboard_i_weight ?? 0),
                    'cardboard_ii_weight' => (float) ($package->cardboard_ii_weight ?? 0),
                    'palette_weight' => (float) ($package->palette_weight ?? 0),
                ],
            ])
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private static function packageProfileOptions(): array
    {
        return Package::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Package $package): array => [
                (int) $package->id => sprintf(
                    '%s (palette: %s items)',
                    (string) $package->name,
                    (int) ($package->items_on_palette ?? 0),
                ),
            ])
            ->all();
    }

    private static function defaultPackageProfileId(): ?int
    {
        $id = Package::query()->orderBy('id')->value('id');

        return filled($id) ? (int) $id : null;
    }

    /**
     * Customer select: "Company name (First name Last name)" — space between name and surname, no comma.
     */
    /**
     * @return array<string|int, string> collection id => name
     */
    private static function collectionFilterOptions(): array
    {
        return CollectionModel::query()
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * Nested options for optgroups: collection name => [ color id => label ].
     *
     * @return array<string, array<string, string>>
     */
    private static function groupedColorFilterOptions(): array
    {
        return CollectionModel::query()
            ->orderBy('name')
            ->with(['colors' => fn ($query) => $query->orderBy('color_code')])
            ->get()
            ->mapWithKeys(function (CollectionModel $collection): array {
                if ($collection->colors->isEmpty()) {
                    return [];
                }

                return [
                    $collection->name => $collection->colors->mapWithKeys(
                        fn (Color $color): array => [
                            (string) $color->getKey() => sprintf(
                                '%s (%s)',
                                $color->color_name,
                                $color->color_code,
                            ),
                        ]
                    )->all(),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int|string>  $colorIds
     * @return array<string, string> id string => label
     */
    private static function colorLabelsForColorIds(array $colorIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $colorIds,
        ), static fn (int $id): bool => $id > 0)));

        if ($ids === []) {
            return [];
        }

        return Color::query()
            ->whereIn('id', $ids)
            ->get()
            ->mapWithKeys(fn (Color $color): array => [
                (string) $color->getKey() => sprintf(
                    '%s (%s)',
                    $color->color_name,
                    $color->color_code,
                ),
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    private static function normalizeMultiSelectFilterIds(array $data): array
    {
        $values = $data['values'] ?? null;
        if (! is_array($values) || $values === []) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($id): int => (int) $id,
            $values,
        ), static fn (int $id): bool => $id > 0)));
    }

    private static function formatCustomerOptionLabel(User $record): string
    {
        $person = trim(implode(' ', array_filter([$record->name, $record->surname])));

        if (filled($record->company_name)) {
            return $person !== ''
                ? "{$record->company_name} ({$person})"
                : $record->company_name;
        }

        return $person !== '' ? $person : (string) ($record->name ?? $record->email ?? '');
    }

    /**
     * HTML for order line tooltip: collections as headings, one line per color with amount.
     */
    public static function buildOrderLinesTooltipHtml(Order $order): ?string
    {
        $order->loadMissing(['orderItems.product.productType', 'orderItems.product.color.collection']);

        if ($order->orderItems->isEmpty()) {
            return null;
        }

        $byCollection = $order->orderItems->groupBy(function ($item) {
            $product = $item->product;

            if ($product?->isCatalog()) {
                return $product->productType?->name ?? 'Catalog';
            }

            return $product?->color?->collection?->name ?? '—';
        })->sortKeys();

        $blocks = [];

        foreach ($byCollection as $collectionName => $lines) {
            $lines = $lines->sortBy(function ($item) {
                $product = $item->product;

                if ($product?->isCatalog()) {
                    return $product->name ?? $product->product_code ?? '';
                }

                return $product?->color?->color_code ?? '';
            });
            $safeCollection = htmlspecialchars((string) $collectionName, ENT_QUOTES, 'UTF-8');

            $rows = [];
            foreach ($lines as $item) {
                $product = $item->product;

                if ($product?->isCatalog()) {
                    $label = htmlspecialchars(
                        trim(($product->name ?? '—').' ('.($product->product_code ?? '—').')'),
                        ENT_QUOTES,
                        'UTF-8',
                    );
                } else {
                    $color = $product?->color;
                    $label = $color
                        ? htmlspecialchars($color->color_name.' ('.$color->color_code.')', ENT_QUOTES, 'UTF-8')
                        : '—';
                }

                $rows[] = '• '.$label.': '.(int) $item->amount;
            }

            $blocks[] = '<strong>'.$safeCollection.'</strong><br>'.implode('<br>', $rows);
        }

        return '<div class="fi-order-lines-tooltip">'.implode('<br><br>', $blocks).'</div>';
    }

    /**
     * @return array<Section>
     */
    private static function orderLineItemSections(): array
    {
        $collectionSections = CollectionModel::query()
            ->orderBy('name')
            ->with(['products' => fn ($query) => $query->with(['productType', 'color'])->orderBy('color_id')])
            ->get()
            ->map(function (CollectionModel $collection): Section {
                $upcomingByProductId = self::upcomingCargoByProductIds(
                    $collection->products->pluck('id')->all()
                );

                $heading = self::collectionSectionHeading($collection);

                $cards = $collection->products
                    ->sortBy(fn (Product $product): string => $product->color?->color_code ?? '')
                    ->map(
                        fn (Product $product) => ProductLineItemCard::make(
                            $product,
                            'order_amounts.'.$product->id,
                            $upcomingByProductId[$product->id] ?? null,
                        ),
                    )
                    ->all();

                return Section::make($heading)
                    ->collapsed()
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(4)->schema($cards),
                    ]);
            })
            ->all();

        $catalogSection = self::catalogOrderLineItemSection();

        return $catalogSection === null
            ? $collectionSections
            : [...$collectionSections, $catalogSection];
    }

    private static function catalogOrderLineItemSection(): ?Section
    {
        $products = Product::query()
            ->catalog()
            ->with('productType')
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            return null;
        }

        $upcomingByProductId = self::upcomingCargoByProductIds($products->pluck('id')->all());

        $cards = $products
            ->map(
                fn (Product $product) => ProductLineItemCard::make(
                    $product,
                    'order_amounts.'.$product->id,
                    $upcomingByProductId[$product->id] ?? null,
                ),
            )
            ->all();

        $typeName = $products->first()?->productType?->name ?? 'Catalog';

        return Section::make($typeName)
            ->collapsed()
            ->columnSpanFull()
            ->schema([
                Grid::make(4)->schema($cards),
            ]);
    }

    /**
     * @param  array<int, int|string>  $productIds
     * @return array<int, array{amount: int, estimated_arrival: string|null}>
     */
    private static function upcomingCargoByProductIds(array $productIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        if ($ids === []) {
            return [];
        }

        return CargoItem::query()
            ->selectRaw('cargo_items.product_id as product_id, SUM(cargo_items.amount) as amount, MIN(cargos.estimated_arrival) as estimated_arrival')
            ->join('cargos', 'cargos.id', '=', 'cargo_items.cargo_id')
            ->whereIn('cargo_items.product_id', $ids)
            ->where('cargos.status', '!=', CargoStatus::Received->value)
            ->groupBy('cargo_items.product_id')
            ->get()
            ->mapWithKeys(fn ($row): array => [
                (int) $row->product_id => [
                    'amount' => (int) $row->amount,
                    'estimated_arrival' => $row->estimated_arrival,
                ],
            ])
            ->all();
    }

    private static function collectionSectionHeading(CollectionModel $collection): string
    {
        $price = number_format(self::effectiveDisplayUnitPriceForCollection($collection), 2);

        return sprintf('%s — %s', $collection->name, Money::format((float) $price));
    }

    /**
     * Price labels in the form: customers see their level price per collection when set; otherwise collection default.
     * Other roles see the collection default price.
     */
    private static function effectiveDisplayUnitPriceForCollection(CollectionModel $collection): float
    {
        $user = auth()->user();
        if ($user?->hasRole('customer')) {
            return (float) self::unitPriceForCollection($collection, $user->customer_level_id);
        }

        return (float) $collection->price;
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     */
    public static function syncOrderItemsFromAmounts(Order $order, array $amounts): void
    {
        $order->orderItems()->delete();

        foreach ($amounts as $productId => $amount) {
            $amount = (int) $amount;
            if ($amount <= 0) {
                continue;
            }

            $order->orderItems()->create([
                'product_id' => (int) $productId,
                'amount' => $amount,
            ]);
        }
    }

    public static function recalculateOrderAmount(Order $order): void
    {
        $order->loadMissing(['orderItems.product.productType', 'orderItems.product.color.collection', 'user.customerLevel']);
        $customerLevelId = $order->user?->customer_level_id;

        $total = 0.0;

        foreach ($order->orderItems as $item) {
            $product = $item->product;
            if ($product === null) {
                continue;
            }

            $amount = (int) $item->amount;

            if ($product->isCatalog()) {
                $total += self::catalogLineTotal((float) $product->default_cost, $amount);

                continue;
            }

            $collection = $product->color?->collection;
            if ($collection === null) {
                continue;
            }

            $unit = (float) self::unitPriceForCollection($collection, $customerLevelId);
            $total += self::lineTotal($unit, $product->name, $amount);
        }

        $shipping = SchemaFacade::hasColumn('orders', 'shipping_cost')
            ? (float) $order->shipping_cost
            : 0.0;

        $order->update([
            'amount' => number_format($total + $shipping, 2, '.', ''),
        ]);
    }

    public static function lineTotal(float $unitPrice, mixed $size, int $quantity): float
    {
        $meters = is_numeric($size) ? (float) $size : 0.0;
        $quantity = max(0, $quantity);

        if ($meters > 0) {
            return round($unitPrice * $meters * $quantity, 2);
        }

        return round($unitPrice * $quantity, 2);
    }

    public static function catalogLineTotal(float $unitPrice, int $quantity): float
    {
        return round($unitPrice * max(0, $quantity), 2);
    }

    private static function unitPriceForCollection(CollectionModel $collection, ?int $customerLevelId): string
    {
        if ($customerLevelId !== null) {
            $override = CustomerLevelPrice::query()
                ->where('customer_level_id', $customerLevelId)
                ->where('collection_id', $collection->id)
                ->first();

            if ($override !== null) {
                return (string) $override->price;
            }
        }

        return (string) $collection->price;
    }

    /**
     * @return list<array{collection: string, color: string, product_code: string, size: string, amount: int, unit_price: float, line_total: float}>
     */
    public static function orderLineItemsSummary(Order $order): array
    {
        $order->loadMissing(['orderItems.product.productType', 'orderItems.product.color.collection', 'user']);
        $customerLevelId = $order->user?->customer_level_id;

        return $order->orderItems
            ->sortBy(fn ($item): array => [
                $item->product?->isCatalog()
                    ? ($item->product->productType?->name ?? 'Catalog')
                    : ($item->product?->color?->collection?->name ?? ''),
                $item->product?->isCatalog()
                    ? ($item->product->name ?? '')
                    : ($item->product?->color?->color_code ?? ''),
                $item->product?->product_code ?? '',
            ])
            ->map(function ($item) use ($customerLevelId): array {
                $product = $item->product;
                $amount = (int) $item->amount;

                if ($product?->isCatalog()) {
                    $unit = (float) $product->default_cost;

                    return [
                        'collection' => $product->productType?->name ?? 'Catalog',
                        'color' => $product->name ?? '—',
                        'product_code' => $product->product_code ?? '—',
                        'size' => '—',
                        'amount' => $amount,
                        'unit_price' => $unit,
                        'line_total' => self::catalogLineTotal($unit, $amount),
                    ];
                }

                $collection = $product?->color?->collection;
                $unit = $collection !== null
                    ? (float) self::unitPriceForCollection($collection, $customerLevelId)
                    : 0.0;
                $sizeLabel = $product?->name ?? '—';

                return [
                    'collection' => $collection?->name ?? '—',
                    'color' => $product?->color
                        ? sprintf('%s (%s)', $product->color->color_name, $product->color->color_code)
                        : '—',
                    'product_code' => $product?->product_code ?? '—',
                    'size' => $sizeLabel,
                    'amount' => $amount,
                    'unit_price' => $unit,
                    'line_total' => self::lineTotal($unit, $product?->name, $amount),
                ];
            })
            ->values()
            ->all();
    }

    public static function isApprovedStatus(mixed $status): bool
    {
        if ($status instanceof OrderStatus) {
            return $status === OrderStatus::Approved;
        }

        return (string) $status === OrderStatus::Approved->value;
    }

    public static function isTransitionToApproved(mixed $newStatus, mixed $oldStatus): bool
    {
        return self::isApprovedStatus($newStatus) && ! self::isApprovedStatus($oldStatus);
    }

    public static function isShippedStatus(mixed $status): bool
    {
        if ($status instanceof OrderStatus) {
            return $status === OrderStatus::Shipped;
        }

        return (string) $status === OrderStatus::Shipped->value;
    }

    public static function isTransitionToShipped(mixed $newStatus, mixed $oldStatus): bool
    {
        return self::isShippedStatus($newStatus) && ! self::isShippedStatus($oldStatus);
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     * @return array<int, int>
     */
    public static function normalizeAmounts(array $amounts): array
    {
        $normalized = [];

        foreach ($amounts as $productId => $amount) {
            $amount = (int) $amount;

            if ($amount > 0) {
                $normalized[(int) $productId] = $amount;
            }
        }

        return $normalized;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('#')
                    ->sortable(),

                Tables\Columns\TextColumn::make('user.company_name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (OrderStatus $state): string => $state->getLabel() ?? $state->name)
                    ->sortable()
                    ->action(
                        Action::make('changeStatus')
                            ->modalHeading('Change order status')
                            ->modalDescription('When confirming or shipping an order, you can review the email before sending it.')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options(OrderStatus::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Order $record): void {
                                        if (self::isTransitionToApproved($state, $record->status)) {
                                            $set('email_body', FulfillmentOrderMailer::preview($record));

                                            return;
                                        }

                                        if (self::isTransitionToShipped($state, $record->status)) {
                                            $set('tracking_number', $record->tracking_number ?? '');
                                            $set('email_body', OrderShippedMailer::preview($record, $record->tracking_number));

                                            return;
                                        }

                                        $set('email_body', null);
                                    }),

                                Forms\Components\TextInput::make('tracking_number')
                                    ->label('Tracking number')
                                    ->maxLength(255)
                                    ->visible(fn (Get $get): bool => self::isShippedStatus($get('status')))
                                    ->required(fn (Get $get): bool => self::isShippedStatus($get('status')))
                                    ->live(onBlur: true)
                                    ->afterStateUpdated(function ($state, Set $set, Order $record, Get $get): void {
                                        if (! self::isTransitionToShipped($get('status'), $record->status)) {
                                            return;
                                        }

                                        $set('email_body', OrderShippedMailer::preview($record, is_string($state) ? $state : null));
                                    }),

                                Forms\Components\Textarea::make('email_body')
                                    ->label('Email preview')
                                    ->rows(14)
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get, Order $record): bool => self::isTransitionToApproved($get('status'), $record->status)
                                        || self::isTransitionToShipped($get('status'), $record->status)),
                            ])
                            ->fillForm(fn (Order $record): array => [
                                'status' => $record->status->value,
                                'tracking_number' => $record->tracking_number ?? '',
                                'email_body' => '',
                            ])
                            ->modalSubmitActionLabel('Confirm')
                            ->extraModalFooterActions(fn (Action $action): array => [
                                $action->makeModalSubmitAction('confirmAndSendEmail', arguments: ['sendEmail' => true])
                                    ->label('Confirm and send email')
                                    ->color('success'),
                            ])
                            ->action(function (Order $record, array $data, array $arguments): void {
                                $newStatus = $data['status'] instanceof OrderStatus
                                    ? $data['status']
                                    : OrderStatus::from($data['status']);
                                $oldStatus = $record->status;
                                $shouldSendEmail = (bool) ($arguments['sendEmail'] ?? false);

                                $update = [
                                    'status' => $newStatus,
                                ];

                                if (self::isShippedStatus($newStatus)) {
                                    $update['tracking_number'] = filled($data['tracking_number'] ?? null)
                                        ? $data['tracking_number']
                                        : null;
                                }

                                $record->update($update);

                                if (self::isTransitionToShipped($newStatus, $oldStatus)) {
                                    try {
                                        OrderInvoiceCreator::createFromShippedOrder($record->fresh(['user']));
                                    } catch (\Throwable $exception) {
                                        Notification::make()
                                            ->title('Status updated, but invoice was not created')
                                            ->body($exception->getMessage())
                                            ->warning()
                                            ->send();
                                    }
                                }

                                if (! $shouldSendEmail) {
                                    return;
                                }

                                if (self::isTransitionToApproved($newStatus, $oldStatus)) {
                                    try {
                                        FulfillmentOrderMailer::send(
                                            $record->fresh(['orderItems.product.color.collection', 'user']),
                                            $data['email_body'] ?? null,
                                        );

                                        Notification::make()
                                            ->title('Email sent to fulfillment warehouse')
                                            ->success()
                                            ->send();
                                    } catch (\Throwable $exception) {
                                        Notification::make()
                                            ->title('Status updated, but email was not sent')
                                            ->body($exception->getMessage())
                                            ->warning()
                                            ->send();
                                    }

                                    return;
                                }

                                if (self::isTransitionToShipped($newStatus, $oldStatus)) {
                                    try {
                                        OrderShippedMailer::send(
                                            $record->fresh(['user']),
                                            $data['tracking_number'] ?? null,
                                            $data['email_body'] ?? null,
                                        );

                                        Notification::make()
                                            ->title('Email sent to customer')
                                            ->success()
                                            ->send();
                                    } catch (\Throwable $exception) {
                                        Notification::make()
                                            ->title('Status updated, but email was not sent')
                                            ->body($exception->getMessage())
                                            ->warning()
                                            ->send();
                                    }
                                }
                            }),
                    ),

                Tables\Columns\TextColumn::make('warehouse_email_sent_at')
                    ->label('Warehouse email date')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                TextInputColumn::make('shipping_cost')
                    ->label('Shipping')
                    ->type('number')
                    ->inputMode('decimal')
                    ->prefix(Money::prefix())
                    ->step(0.01)
                    ->rules(['numeric', 'min:0'])
                    ->afterStateUpdated(function ($state, Order $record): void {
                        OrderResource::recalculateOrderAmount($record->fresh());
                    })
                    ->sortable(),

                TextInputColumn::make('tracking_number')
                    ->label('Tracking')
                    ->placeholder('—')
                    ->rules(['nullable', 'string', 'max:255'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total amount')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->tooltip(function (Order $record): ?HtmlString {
                        $html = self::buildOrderLinesTooltipHtml($record);

                        return $html !== null ? new HtmlString($html) : null;
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Order date')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Customer name')
                    ->searchable()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('updated_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('order_date_range')
                    ->label('Order date')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From'),
                        DatePicker::make('until')
                            ->label('Until'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        if (! SchemaFacade::hasColumn('orders', 'order_date')) {
                            return;
                        }

                        $from = $data['from'] ?? null;
                        $until = $data['until'] ?? null;

                        if (blank($from) && blank($until)) {
                            return;
                        }

                        $column = $query->getModel()->qualifyColumn('order_date');

                        if (filled($from)) {
                            $query->whereDate($column, '>=', $from);
                        }

                        if (filled($until)) {
                            $query->whereDate($column, '<=', $until);
                        }
                    }),

                SelectFilter::make('user_id')
                    ->label('Customer')
                    ->relationship('user', 'name', fn (Builder $query) => $query->role('customer'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->getOptionLabelFromRecordUsing(fn (User $record): string => self::formatCustomerOptionLabel($record)),

                SelectFilter::make('order_collections')
                    ->label('Collections')
                    ->multiple()
                    ->options(fn (): array => self::collectionFilterOptions())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $ids = self::normalizeMultiSelectFilterIds($data);
                        if ($ids === []) {
                            return;
                        }

                        $query->whereHas(
                            'orderItems.product.color',
                            fn (Builder $q) => $q->whereIn(
                                $q->getModel()->qualifyColumn('collection_id'),
                                $ids,
                            ),
                        );
                    }),

                SelectFilter::make('order_colors')
                    ->label('Colors')
                    ->multiple()
                    ->options(fn (): array => self::groupedColorFilterOptions())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->indicateUsing(function (SelectFilter $filter, array $state): array {
                        $values = $state['values'] ?? null;
                        if (! is_array($values) || $values === []) {
                            return [];
                        }

                        $map = self::colorLabelsForColorIds($values);
                        $labels = collect($values)
                            ->map(fn ($id) => $map[(string) (int) $id] ?? null)
                            ->filter()
                            ->join(', ');

                        if ($labels === '') {
                            return [];
                        }

                        $name = $filter->getLabel();

                        return [Indicator::make("{$name}: {$labels}")];
                    })
                    ->query(function (Builder $query, array $data): void {
                        $ids = self::normalizeMultiSelectFilterIds($data);
                        if ($ids === []) {
                            return;
                        }

                        $query->whereHas('orderItems.product', fn (Builder $q) => $q->whereIn(
                            $q->getModel()->qualifyColumn('color_id'),
                            $ids,
                        ));
                    }),
            ])
            ->recordActions([
                Action::make('quick_view')
                    ->label('Quick view')
                    ->icon('heroicon-o-eye')
                    ->modalHeading(fn (Order $record): string => 'Order #'.$record->id.' items')
                    ->modalContent(fn (Order $record): Htmlable => new HtmlString(
                        view('filament.admin.components.order-items-quick-view', [
                            'order' => $record->loadMissing(['orderItems.product.color.collection', 'user']),
                        ])->render()
                    ))
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel('Close')
                    ->modalWidth('5xl'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'user',
            'orderItems.product.color.collection',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
