<?php

namespace App\Filament\Admin\Resources\Cargos;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\Cargos\Pages;
use App\Filament\Admin\Support\ProductLineItemCard;
use App\Models\Cargo;
use App\Models\Collection as CollectionModel;
use App\Models\ImportTax;
use App\Models\Supplier;
use App\Models\Product;
use App\Support\Money;
use App\Services\CargoReceiver;
use App\Services\WarehouseOrderMailer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Component;
use UnitEnum;

class CargoResource extends Resource
{
    /** @var array<int, float>|null */
    private static ?array $productDefaultCosts = null;

    private static ?Cargo $costContextCargo = null;

    protected static ?string $model = Cargo::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Warehouse order';

    protected static ?string $modelLabel = 'Warehouse order';

    protected static ?string $pluralModelLabel = 'Warehouse orders';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required()
                    ->default(fn (): ?int => self::defaultSupplierId())
                    ->live()
                    ->afterStateUpdated(function ($state, $old, Get $get, Set $set): void {
                        if (blank($state) || blank($old) || (int) $state === (int) $old) {
                            return;
                        }

                        self::clearAmountsForOtherSuppliers(
                            (int) $state,
                            is_array($get('cargo_amounts')) ? $get('cargo_amounts') : [],
                            $set,
                        );
                    }),

                Forms\Components\Placeholder::make('supplier_items_hint')
                    ->label('')
                    ->content('Select a supplier to see orderable collections.')
                    ->visible(fn (Get $get): bool => blank($get('supplier_id')))
                    ->dehydrated(false)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('tracking')
                    ->maxLength(255)
                    ->label('Tracking'),

                Forms\Components\DatePicker::make('date_shipped')
                    ->native(false)
                    ->label('Order date')
                    ->default(fn (): string => Carbon::today()->toDateString()),

                Forms\Components\DatePicker::make('estimated_arrival')
                    ->required()
                    ->native(false)
                    ->label('Estimated arrival')
                    ->default(fn (): string => Carbon::today()->addMonth()->toDateString()),

                Forms\Components\Select::make('status')
                    ->options(CargoStatus::class)
                    ->required()
                    ->native(false)
                    ->default(CargoStatus::Draft),

                Section::make('Costs')
                    ->columnSpanFull()
                    ->schema([
                        Grid::make(2)->schema([
                            Forms\Components\Select::make('import_tax_id')
                                ->label('Import tax')
                                ->relationship(
                                    'importTax',
                                    'name',
                                    fn (Builder $query) => $query->orderBy('id'),
                                )
                                ->getOptionLabelFromRecordUsing(
                                    fn ($record): string => $record->name.' ('.number_format((float) $record->rate, 2).'%)',
                                )
                                ->default(fn (): ?int => self::defaultImportTaxId())
                                ->searchable()
                                ->preload()
                                ->native(false)
                                ->live()
                                ->extraAttributes([
                                    'x-on:change' => '$dispatch(\'cargo-cost-recalculate\')',
                                ]),

                            Forms\Components\TextInput::make('shipping_cost')
                                ->label('Shipping cost')
                                ->numeric()
                                ->minValue(0)
                                ->default(0)
                                ->step(0.01)
                                ->prefix(Money::prefix())
                                ->live()
                                ->extraInputAttributes([
                                    'data-cargo-shipping-cost' => 'true',
                                    'x-on:input' => '$dispatch(\'cargo-cost-recalculate\')',
                                    'x-on:change' => '$dispatch(\'cargo-cost-recalculate\')',
                                ]),
                        ]),

                        View::make('filament.admin.components.cargo-calculated-costs')
                            ->columnSpanFull()
                            ->viewData(function (Get $get): array {
                                $amounts = $get('cargo_amounts');
                                $costs = $get('cargo_costs');

                                return [
                                    'productCosts' => self::mergeProductCosts(
                                        is_array($costs) ? $costs : [],
                                    ),
                                    'importTaxRates' => self::importTaxRates(),
                                    'initialWithoutShipping' => self::calculateFullCostWithoutShipping(
                                        is_array($amounts) ? $amounts : [],
                                        is_array($costs) ? $costs : [],
                                    ),
                                    'initialShippingCost' => (float) ($get('shipping_cost') ?? 0),
                                    'initialImportTaxId' => $get('import_tax_id'),
                                ];
                            })
                            ->schema([
                                Forms\Components\TextInput::make('additional_cost')
                                    ->label('Additional cost')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->step(0.01)
                                    ->prefix(Money::prefix()),
                            ]),

                        Actions::make([
                            Action::make('receiveAndImport')
                                ->label('Received. Import it')
                                ->button()
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Receive warehouse order and import stock?')
                                ->modalDescription('The warehouse order will be marked as received. Product stock will be updated and received order lines will be created.')
                                ->visible(fn (?Cargo $record): bool => filled($record) && $record->status !== CargoStatus::Received)
                                ->action(function (Component $livewire): void {
                                    if (method_exists($livewire, 'receiveAndImport')) {
                                        $livewire->receiveAndImport();
                                    }
                                }),
                        ])->columnSpanFull(),
                    ]),

                ...self::cargoLineItemSections(),
            ]);
    }

    /**
     * @return array<Section>
     */
    private static function cargoLineItemSections(): array
    {
        $collectionSections = CollectionModel::query()
            ->orderBy('name')
            ->with(['products' => fn ($query) => $query->with(['productType', 'color'])->orderBy('color_id')])
            ->get()
            ->map(function (CollectionModel $collection): Section {
                $filterField = 'cargo_line_filters.'.$collection->id;

                $cards = $collection->products
                    ->sortBy(fn (Product $product): string => $product->color?->color_code ?? '')
                    ->map(
                        fn (Product $product) => ProductLineItemCard::make(
                            $product,
                            'cargo_amounts.'.$product->id,
                            null,
                            $filterField,
                            recalculateCosts: true,
                            costField: 'cargo_costs.'.$product->id,
                        ),
                    )
                    ->all();

                return Section::make(function (Get $get) use ($collection): string {
                    $totalOrdered = $collection->products->sum(
                        fn (Product $product): int => (int) ($get('cargo_amounts.'.$product->id) ?? 0),
                    );

                    return $collection->name.' ('.$totalOrdered.')';
                })
                    ->collapsed()
                    ->columnSpanFull()
                    ->visible(function (Get $get) use ($collection): bool {
                        $supplierId = $get('supplier_id');

                        if (blank($supplierId) || blank($collection->supplier_id)) {
                            return false;
                        }

                        return (int) $supplierId === (int) $collection->supplier_id;
                    })
                    ->schema([
                        Forms\Components\Select::make($filterField)
                            ->label('Show items')
                            ->options([
                                'all' => 'All',
                                'unordered' => 'Unordered only',
                                'ordered' => 'Ordered only',
                            ])
                            ->default('all')
                            ->selectablePlaceholder(false)
                            ->afterStateHydrated(function (Forms\Components\Select $component, $state): void {
                                if (blank($state)) {
                                    $component->state('all');
                                }
                            })
                            ->native(false)
                            ->live()
                            ->dehydrated(false),

                        Grid::make(4)->schema($cards),
                    ]);
            })
            ->all();

        $catalogSection = self::catalogCargoLineItemSection();

        return $catalogSection === null
            ? $collectionSections
            : [...$collectionSections, $catalogSection];
    }

    private static function catalogCargoLineItemSection(): ?Section
    {
        $products = Product::query()
            ->catalog()
            ->with('productType')
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            return null;
        }

        $filterField = 'cargo_line_filters.catalog';

        $cards = $products
            ->map(
                fn (Product $product) => ProductLineItemCard::make(
                    $product,
                    'cargo_amounts.'.$product->id,
                    null,
                    $filterField,
                    recalculateCosts: true,
                    costField: 'cargo_costs.'.$product->id,
                ),
            )
            ->all();

        $typeName = $products->first()?->productType?->name ?? 'Catalog';

        return Section::make(function (Get $get) use ($products, $typeName): string {
            $totalOrdered = $products->sum(
                fn (Product $product): int => (int) ($get('cargo_amounts.'.$product->id) ?? 0),
            );

            return $typeName.' ('.$totalOrdered.')';
        })
            ->collapsed()
            ->columnSpanFull()
            ->visible(fn (Get $get): bool => filled($get('supplier_id')))
            ->schema([
                Forms\Components\Select::make($filterField)
                    ->label('Show items')
                    ->options([
                        'all' => 'All',
                        'unordered' => 'Unordered only',
                        'ordered' => 'Ordered only',
                    ])
                    ->default('all')
                    ->selectablePlaceholder(false)
                    ->afterStateHydrated(function (Forms\Components\Select $component, $state): void {
                        if (blank($state)) {
                            $component->state('all');
                        }
                    })
                    ->native(false)
                    ->live()
                    ->dehydrated(false),

                Grid::make(4)->schema($cards),
            ]);
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     */
    /**
     * @param  array<string|int, mixed>  $amounts
     * @param  array<string|int, mixed>  $costs
     */
    public static function calculateFullCostWithoutShipping(array $amounts, array $costs = []): float
    {
        $unitCosts = self::mergeProductCosts($costs);
        $total = 0.0;

        foreach ($amounts as $productId => $amount) {
            $amount = (int) $amount;
            if ($amount <= 0) {
                continue;
            }

            $total += $amount * ($unitCosts[(int) $productId] ?? $unitCosts[(string) $productId] ?? 0.0);
        }

        return round($total, 2);
    }

    /**
     * @param  array<string|int, mixed>  $costs
     * @return array<int|string, float>
     */
    public static function mergeProductCosts(array $costs = []): array
    {
        $unitCosts = self::productDefaultCosts();

        foreach ($costs as $productId => $cost) {
            if ($cost === null || $cost === '') {
                continue;
            }

            $unitCosts[(int) $productId] = (float) $cost;
        }

        return $unitCosts;
    }

    public static function defaultSupplierId(): ?int
    {
        $supplierIds = Supplier::query()
            ->orderBy('name')
            ->pluck('id');

        if ($supplierIds->count() !== 1) {
            return null;
        }

        return (int) $supplierIds->first();
    }

    public static function defaultImportTaxId(): ?int
    {
        $id = ImportTax::query()->orderBy('id')->value('id');

        return $id !== null ? (int) $id : null;
    }

    public static function setCostContext(?Cargo $cargo): void
    {
        self::$costContextCargo = $cargo;
        self::$productDefaultCosts = null;
    }

    public static function importCargoItemsToProductStock(Cargo $cargo): void
    {
        $cargo->loadMissing('cargoItems.product');

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0 || $item->product === null) {
                continue;
            }

            $item->product->increment('current_amount', $item->amount);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function stripVirtualCargoFormFields(array $data): array
    {
        unset($data['cargo_amounts'], $data['cargo_costs'], $data['cargo_line_filters']);

        return $data;
    }

    /**
     * @return array<int|string, float>
     */
    private static function importTaxRates(): array
    {
        return ImportTax::query()
            ->pluck('rate', 'id')
            ->map(fn ($rate): float => (float) $rate)
            ->all();
    }

    /**
     * @return array<int, float>
     */
    private static function productDefaultCosts(): array
    {
        if (self::$productDefaultCosts !== null) {
            return self::$productDefaultCosts;
        }

        $costs = Product::query()
            ->pluck('default_cost', 'id')
            ->map(fn ($cost): float => (float) $cost)
            ->all();

        if (self::$costContextCargo !== null) {
            self::$costContextCargo->loadMissing('cargoItems');

            foreach (self::$costContextCargo->cargoItems as $item) {
                $costs[$item->product_id] = (float) $item->self_cost;
            }
        }

        self::$productDefaultCosts = $costs;

        return self::$productDefaultCosts;
    }

    /**
     * @return array<int>
     */
    public static function productIdsForSupplier(?int $supplierId): array
    {
        if (blank($supplierId)) {
            return [];
        }

        $leatherIds = Product::query()
            ->whereHas('color.collection', fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $catalogIds = Product::query()
            ->catalog()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique([...$leatherIds, ...$catalogIds]));
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     */
    public static function filterAmountsForSupplier(?int $supplierId, array $amounts): array
    {
        $allowedProductIds = self::productIdsForSupplier($supplierId);

        if ($allowedProductIds === []) {
            return [];
        }

        return collect($amounts)
            ->filter(fn ($amount, $productId): bool => in_array((int) $productId, $allowedProductIds, true))
            ->all();
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     */
    public static function clearAmountsForOtherSuppliers(?int $supplierId, array $amounts, Set $set): void
    {
        $allowedProductIds = self::productIdsForSupplier($supplierId);

        foreach ($amounts as $productId => $amount) {
            if ((int) $amount <= 0) {
                continue;
            }

            if (! in_array((int) $productId, $allowedProductIds, true)) {
                $set('cargo_amounts.'.$productId, 0);
                $set('cargo_costs.'.$productId, null);
            }
        }
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     * @param  array<string|int, mixed>  $costs
     */
    public static function syncCargoItemsFromAmounts(Cargo $cargo, array $amounts, array $costs = []): void
    {
        $amounts = self::filterAmountsForSupplier($cargo->supplier_id, $amounts);
        $allowedProductIds = self::productIdsForSupplier($cargo->supplier_id);

        $costs = collect($costs)
            ->filter(fn ($cost, $productId): bool => in_array((int) $productId, $allowedProductIds, true))
            ->all();
        $productIds = collect($amounts)
            ->filter(fn ($amount): bool => (int) $amount > 0)
            ->keys()
            ->map(fn ($productId): int => (int) $productId)
            ->all();

        $defaultCosts = Product::query()
            ->whereIn('id', $productIds)
            ->pluck('default_cost', 'id')
            ->map(fn ($cost): float => (float) $cost)
            ->all();

        $cargo->cargoItems()->delete();

        foreach ($amounts as $productId => $amount) {
            $amount = (int) $amount;
            if ($amount <= 0) {
                continue;
            }

            $productId = (int) $productId;
            $selfCost = isset($costs[$productId]) || isset($costs[(string) $productId])
                ? (float) ($costs[$productId] ?? $costs[(string) $productId])
                : ($defaultCosts[$productId] ?? 0.0);

            $cargo->cargoItems()->create([
                'product_id' => $productId,
                'amount' => $amount,
                'self_cost' => $selfCost,
            ]);
        }

        self::$productDefaultCosts = null;
    }

    public static function isOrderedStatus(mixed $status): bool
    {
        if ($status instanceof CargoStatus) {
            return $status === CargoStatus::Ordered;
        }

        return (string) $status === CargoStatus::Ordered->value;
    }

    public static function isTransitionToOrdered(mixed $newStatus, mixed $oldStatus): bool
    {
        return self::isOrderedStatus($newStatus) && ! self::isOrderedStatus($oldStatus);
    }

    /**
     * @return array<string|int, int>
     */
    public static function amountsFromCargo(Cargo $cargo): array
    {
        $cargo->loadMissing('cargoItems');

        $amounts = [];

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount > 0) {
                $amounts[$item->product_id] = $item->amount;
            }
        }

        return self::filterAmountsForSupplier($cargo->supplier_id, $amounts);
    }

    public static function emailPreviewForCargo(Cargo $cargo): string
    {
        $supplierId = $cargo->supplier_id ? (int) $cargo->supplier_id : null;

        return WarehouseOrderMailer::preview($supplierId, self::amountsFromCargo($cargo));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('supplier.name')
                    ->label('Supplier')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('tracking')
                    ->searchable()
                    ->placeholder('—')
                    ->label('Tracking'),

                Tables\Columns\TextColumn::make('date_shipped')
                    ->date()
                    ->sortable()
                    ->label('Order date')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('estimated_arrival')
                    ->date()
                    ->sortable()
                    ->label('Estimated date'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CargoStatus $state): string => $state->getLabel() ?? $state->name)
                    ->sortable()
                    ->action(
                        Action::make('changeStatus')
                            ->modalHeading('Change warehouse order status')
                            ->modalDescription('When changing the status to Ordered, you can review the email before sending it to the supplier. Changing the status to Received will import stock, create received orders, and import tax payments.')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->options(CargoStatus::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->afterStateUpdated(function ($state, Set $set, Cargo $record): void {
                                        if (self::isTransitionToOrdered($state, $record->status)) {
                                            $set('email_body', self::emailPreviewForCargo($record));

                                            return;
                                        }

                                        $set('email_body', null);
                                    }),

                                Forms\Components\Textarea::make('email_body')
                                    ->label('Email')
                                    ->helperText('Review and edit the supplier email before sending.')
                                    ->rows(14)
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get, Cargo $record): bool => self::isTransitionToOrdered($get('status'), $record->status)),
                            ])
                            ->fillForm(fn (Cargo $record): array => [
                                'status' => $record->status->value,
                                'email_body' => '',
                            ])
                            ->modalSubmitActionLabel('Confirm')
                            ->extraModalFooterActions(fn (Action $action, Get $get, Cargo $record): array => self::isTransitionToOrdered($get('status'), $record->status)
                                ? [
                                    $action->makeModalSubmitAction('confirmAndSendEmail', arguments: ['sendEmail' => true])
                                        ->label('Confirm and send email')
                                        ->color('success'),
                                ]
                                : [])
                            ->action(function (Cargo $record, array $data, array $arguments): void {
                                $newStatus = $data['status'] instanceof CargoStatus
                                    ? $data['status']
                                    : CargoStatus::from($data['status']);
                                $oldStatus = $record->status;
                                $shouldSendEmail = (bool) ($arguments['sendEmail'] ?? false);

                                if ($newStatus === CargoStatus::Received) {
                                    try {
                                        CargoReceiver::receiveAndImport($record);
                                    } catch (\RuntimeException $exception) {
                                        Notification::make()
                                            ->title($exception->getMessage())
                                            ->warning()
                                            ->send();

                                        return;
                                    }

                                    Notification::make()
                                        ->title('Warehouse order received and stock imported')
                                        ->success()
                                        ->send();

                                    return;
                                }

                                $record->update([
                                    'status' => $newStatus,
                                ]);

                                if (! self::isTransitionToOrdered($newStatus, $oldStatus)) {
                                    if ($newStatus !== $oldStatus) {
                                        Notification::make()
                                            ->title('Warehouse order status updated')
                                            ->success()
                                            ->send();
                                    }

                                    return;
                                }

                                if (! $shouldSendEmail) {
                                    Notification::make()
                                        ->title('Warehouse order status updated')
                                        ->success()
                                        ->send();

                                    return;
                                }

                                try {
                                    WarehouseOrderMailer::send(
                                        $record->fresh(['supplier.mailTemplate', 'cargoItems.product.color.collection']),
                                        $data['email_body'] ?? null,
                                    );

                                    Notification::make()
                                        ->title('Email sent to supplier')
                                        ->success()
                                        ->send();
                                } catch (\Throwable $exception) {
                                    Notification::make()
                                        ->title('Status updated, but email was not sent')
                                        ->body($exception->getMessage())
                                        ->warning()
                                        ->send();
                                }
                            }),
                    ),

                Tables\Columns\TextColumn::make('email_sent_at')
                    ->label('Email send date')
                    ->dateTime()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('importTax.name')
                    ->label('Import tax')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('shipping_cost')
                    ->label('Shipping cost')
                    ->money(Money::currency())
                    ->sortable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('supplier_id')
                    ->label('Supplier')
                    ->relationship('supplier', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->recordActions([
                \Filament\Actions\EditAction::make(),
            ])
            ->toolbarActions([
                \Filament\Actions\BulkActionGroup::make([
                    \Filament\Actions\DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['supplier', 'importTax']);
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
            'index' => Pages\ListCargos::route('/'),
            'create' => Pages\CreateCargo::route('/create'),
            'edit' => Pages\EditCargo::route('/{record}/edit'),
        ];
    }
}
