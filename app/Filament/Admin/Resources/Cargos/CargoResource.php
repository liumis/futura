<?php

namespace App\Filament\Admin\Resources\Cargos;

use App\Enums\CargoStatus;
use App\Filament\Admin\Resources\ImportTaxPayments\ImportTaxPaymentResource;
use App\Filament\Admin\Support\ProductCatalogPicker;
use App\Filament\Admin\Support\ProductLineItemCard;
use App\Models\Cargo;
use App\Models\Collection as CollectionModel;
use App\Models\CustomsContact;
use App\Models\ImportTax;
use App\Models\ImportTaxPayment;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\CargoCostCalculator;
use App\Services\CargoReceiver;
use App\Services\SharepointCustomsInvoiceUploader;
use App\Services\WarehouseOrderMailer;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use UnitEnum;

class CargoResource extends Resource
{
    /** @var array<int, float>|null */
    private static ?array $productDefaultCosts = null;

    /** @var array<int, float|null>|null */
    private static ?array $productSizeMeters = null;

    private static ?Cargo $costContextCargo = null;

    protected static ?string $model = Cargo::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-truck';

    protected static ?string $navigationLabel = 'Warehouse order';

    protected static ?string $modelLabel = 'Warehouse order';

    protected static ?string $pluralModelLabel = 'Warehouse orders';

    protected static string|UnitEnum|null $navigationGroup = 'Stock';

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

                Forms\Components\DatePicker::make('actual_date')
                    ->native(false)
                    ->label('Actual date')
                    ->helperText('Defaults to today when the order is received; you can change it.'),

                Forms\Components\Select::make('status')
                    ->options(CargoStatus::class)
                    ->required()
                    ->native(false)
                    ->default(CargoStatus::Draft)
                    ->live()
                    ->afterStateUpdated(function ($state, Set $set, Get $get): void {
                        $status = $state instanceof CargoStatus
                            ? $state
                            : CargoStatus::tryFrom((string) $state);

                        if ($status === CargoStatus::Received && blank($get('actual_date'))) {
                            $set('actual_date', Carbon::today()->toDateString());
                        }
                    }),

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
                                ->live(),

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
                                ]),
                        ]),

                        View::make('filament.admin.components.cargo-calculated-costs')
                            ->columnSpanFull()
                            ->viewData(function (Get $get): array {
                                $amounts = is_array($get('cargo_amounts')) ? $get('cargo_amounts') : [];
                                $costs = is_array($get('cargo_costs')) ? $get('cargo_costs') : [];

                                if (self::$costContextCargo instanceof Cargo) {
                                    self::$costContextCargo->loadMissing('cargoItems');

                                    foreach (self::$costContextCargo->cargoItems as $item) {
                                        if ((int) $item->amount <= 0) {
                                            continue;
                                        }

                                        $productKey = (string) $item->product_id;
                                        $formAmount = (int) ($amounts[$productKey] ?? $amounts[$item->product_id] ?? 0);

                                        if ($formAmount <= 0) {
                                            $amounts[$productKey] = (int) $item->amount;
                                        }

                                        if (! array_key_exists($productKey, $costs) && ! array_key_exists($item->product_id, $costs)) {
                                            $costs[$productKey] = (float) $item->self_cost;
                                        }
                                    }
                                }

                                return [
                                    'productCosts' => self::mergeProductCosts($costs),
                                    'productSizes' => self::productSizeMeters(),
                                    'importTaxRates' => self::importTaxRates(),
                                    'initialWithoutShipping' => self::calculateFullCostWithoutShipping($amounts, $costs),
                                    'initialShippingCost' => (float) ($get('shipping_cost') ?? self::$costContextCargo?->shipping_cost ?? 0),
                                    'initialAdditionalCost' => (float) ($get('additional_cost') ?? self::$costContextCargo?->additional_cost ?? 0),
                                    'initialImportTaxId' => $get('import_tax_id') ?? self::$costContextCargo?->import_tax_id,
                                ];
                            })
                            ->schema([
                                Forms\Components\TextInput::make('additional_cost')
                                    ->label('Additional cost')
                                    ->numeric()
                                    ->minValue(0)
                                    ->default(0)
                                    ->step(0.01)
                                    ->prefix(Money::prefix())
                                    ->live()
                                    ->extraInputAttributes([
                                        'data-cargo-additional-cost' => 'true',
                                    ]),
                            ]),

                        Actions::make([
                            Action::make('receiveAndImport')
                                ->label('Received. Import it')
                                ->button()
                                ->color('success')
                                ->requiresConfirmation()
                                ->modalHeading('Receive warehouse order and import stock?')
                                ->modalDescription('The warehouse order will be marked as received. Shipping and other costs will be divided across imported items and merged into current stock cost.')
                                ->visible(fn (?Cargo $record): bool => filled($record) && $record->status !== CargoStatus::Received)
                                ->action(function (Component $livewire): void {
                                    if (method_exists($livewire, 'receiveAndImport')) {
                                        $livewire->receiveAndImport();
                                    }
                                }),
                        ])->columnSpanFull(),
                    ]),

                Section::make('Customs documents')
                    ->description('Upload customs documents to SharePoint, then email them to a customs contact.')
                    ->columnSpanFull()
                    ->visible(function (Get $get, ?Cargo $record): bool {
                        if ($record === null) {
                            return false;
                        }

                        $status = CargoStatus::tryFromMixed($get('status') ?? $record->status);

                        return $status?->allowsCustomsDocuments() ?? false;
                    })
                    ->schema([
                        Forms\Components\Placeholder::make('customs_documents_list')
                            ->label('On SharePoint')
                            ->content(fn (?Cargo $record): HtmlString => $record?->customsDocumentsListHtml()
                                ?? new HtmlString('<p class="text-sm text-gray-500">Save the order first.</p>'))
                            ->columnSpanFull(),

                        Forms\Components\FileUpload::make('customs_document_uploads')
                            ->label('Upload documents')
                            ->multiple()
                            ->disk('public')
                            ->directory('cargo-customs-documents-tmp')
                            ->storeFileNamesIn('customs_document_upload_names')
                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                            ->helperText(UploadLimits::withExistingNote('Files are stored on SharePoint when you save. Temporary local copies are removed after upload.'))
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                                'application/msword',
                                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                                'application/vnd.ms-excel',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                            ])
                            ->columnSpanFull()
                            ->visible(fn (?Cargo $record): bool => filled($record) && $record->status !== CargoStatus::Received)
                            ->disabled(fn (?Cargo $record): bool => $record?->status === CargoStatus::Received),

                        Forms\Components\Select::make('customs_contact_id')
                            ->label('Customs contact')
                            ->options(fn (): array => CustomsContact::options())
                            ->default(fn (?Cargo $record): ?int => $record?->customs_contact_id ?? CustomsContact::defaultId())
                            ->searchable()
                            ->preload()
                            ->native(false)
                            ->required(fn (): bool => CustomsContact::countContacts() > 1)
                            ->disabled(fn (): bool => CustomsContact::countContacts() === 1)
                            ->dehydrated()
                            ->visible(fn (): bool => CustomsContact::countContacts() > 0)
                            ->helperText(fn (): ?string => CustomsContact::countContacts() > 1
                                ? 'Select which customs company to email.'
                                : null),

                        Forms\Components\Placeholder::make('customs_contact_missing')
                            ->label('Customs contact')
                            ->content('No customs contacts configured. Add one under Financial options → Customs contacts.')
                            ->visible(fn (): bool => CustomsContact::countContacts() === 0),

                        Forms\Components\Placeholder::make('customs_email_sent_at_display')
                            ->label('Last customs email')
                            ->content(fn (?Cargo $record): string => $record?->customs_email_sent_at
                                ? $record->customs_email_sent_at->format('Y-m-d H:i')
                                : 'Not sent yet')
                            ->visible(fn (?Cargo $record): bool => filled($record)),

                        Actions::make([
                            Action::make('sendCustomsEmail')
                                ->label('Send email to customs')
                                ->button()
                                ->color('primary')
                                ->icon('heroicon-o-envelope')
                                ->visible(fn (?Cargo $record): bool => filled($record)
                                    && $record->hasCustomsDocuments()
                                    && $record->status !== CargoStatus::Received)
                                ->action(function (Component $livewire): void {
                                    if (method_exists($livewire, 'mountAction')) {
                                        $livewire->mountAction('sendCustomsEmail');
                                    }
                                }),
                        ])->columnSpanFull(),
                    ]),

                View::make('filament.admin.components.order-line-section-totals')
                    ->columnSpanFull(),

                ...self::cargoLineItemSections(),
            ]);
    }

    /**
     * @return array<int, Section>
     */
    private static function cargoLineItemSections(): array
    {
        $groups = [];

        foreach (
            CollectionModel::query()
            ->orderBy('name')
                ->with(['products' => fn ($query) => $query
                    ->artificialLeather()
                    ->with(['productType', 'color.collection'])
                    ->orderBy('color_id')])
                ->get() as $collection
        ) {
            $products = $collection->products
                ->sortBy(fn (Product $product): string => $product->color?->color_code ?? '')
                ->values();

            if ($products->isEmpty()) {
                continue;
            }

            $groups[] = self::makeCargoOrderCollectionGroup(
                sectionKey: 'collection-'.$collection->id,
                heading: (string) $collection->name,
                products: $products->all(),
                visible: function (Get $get) use ($collection): bool {
                    $supplierId = $get('supplier_id');

                    if (blank($supplierId) || blank($collection->supplier_id)) {
                        return false;
                    }

                    return (int) $supplierId === (int) $collection->supplier_id;
                },
            );
        }

        return [
            Section::make('Products')
                ->description('Artificial leather for this supplier. Collections start collapsed — search or Expand all, then enter amounts.')
                ->columnSpanFull()
                ->compact()
                ->visible(fn (Get $get): bool => filled($get('supplier_id')))
                ->extraAttributes(fn (Get $get): array => [
                    'class' => 'fi-cargo-order-wrap',
                    // Avoid re-morphing hundreds of product rows on every amount sync.
                    // Recreate the tree when the supplier changes.
                    'wire:ignore' => true,
                    'wire:key' => 'cargo-order-products-'.(string) ($get('supplier_id') ?: '0'),
                ])
                ->schema([
                    View::make('filament.admin.components.cargo-order-table-chrome')
                        ->columnSpanFull(),
                    ...$groups,
                ]),
        ];
    }

    /**
     * @param  list<Product>  $products
     */
    private static function makeCargoOrderCollectionGroup(
        string $sectionKey,
        string $heading,
        array $products,
        callable $visible,
    ): Group {
        $productIds = array_map(
            fn (Product $product): int => (int) $product->id,
            $products,
        );

        $rows = array_map(
            fn (Product $product): Group => self::cargoOrderProductRow($product, $heading),
            $products,
        );

        return Group::make([
            Forms\Components\Placeholder::make('cargo_order_heading_'.$sectionKey)
                ->hiddenLabel()
                ->dehydrated(false)
                ->columnSpanFull()
                ->content(ProductCatalogPicker::collectionHeadingHtml($heading)),
            ...$rows,
        ])
            ->key('cargo-line-section-'.$sectionKey)
            ->columns(['default' => 5])
            ->columnSpanFull()
            ->visible($visible)
            ->extraAttributes([
                'class' => 'fi-cargo-order-collection',
                'data-collection-key' => $sectionKey,
                'data-fi-order-section' => '1',
                'data-fi-order-product-ids' => json_encode(array_values($productIds)),
            ]);
    }

    private static function cargoOrderProductRow(Product $product, string $collectionName): Group
    {
        $search = strtolower(trim(implode(' ', array_filter([
            $collectionName,
            $product->color?->color_name,
            $product->color?->color_code,
            $product->product_code,
            $product->alternative_code,
            $product->name,
        ], fn ($value): bool => filled($value)))));

        $nameHtml = '<div class="fi-cargo-order-name">'.e($product->color?->color_name ?? '—').'</div>';
        $colorId = (int) ($product->color_id ?? 0);
        $recommended = (int) ($product->color?->recommended_stock ?? 200);
        $stockMeters = $product->stockMeters();
        $sizeMeters = $product->sizeMeters();
        $rolls = (int) $product->current_amount;
        $isLowStock = $colorId > 0 && $recommended > 0 && $stockMeters < $recommended;

        $formatMeters = static function (float $meters): string {
            return rtrim(rtrim(number_format($meters, 2, '.', ''), '0'), '.').'m';
        };

        $metaHtml = $sizeMeters !== null
            ? e($formatMeters($sizeMeters)).' × '.e((string) $rolls).' = '
                .'<strong class="fi-cargo-order-meta-total">'.e($formatMeters($stockMeters)).'</strong>'
            : e((string) $rolls).' pcs';

        $recommendedControl = $colorId > 0
            ? '<button type="button"'
                .' class="fi-cargo-order-rec-btn"'
                .' data-recommended-stock="1"'
                .' data-color-id="'.$colorId.'"'
                .' data-recommended-value="'.$recommended.'"'
                .' title="Edit recommended stock">'
                .'<span data-rec-label>'.$recommended.'</span> m'
                .'</button>'
            : '<span class="fi-cargo-order-rec-btn is-disabled">'.$recommended.' m</span>';

        $amountInputAttributes = [
            'data-cargo-product-amount' => (string) $product->id,
            'inputmode' => 'numeric',
        ];

        $costInputAttributes = [
            'data-cargo-product-cost' => (string) $product->id,
        ];

        $rowClass = 'fi-cargo-order-row';
        if ($isLowStock) {
            $rowClass .= ' fi-cargo-order-row--low-stock';
        }

        return Group::make([
            Forms\Components\Placeholder::make('cargo_swatch_'.$product->id)
                ->hiddenLabel()
                ->dehydrated(false)
                ->content(new HtmlString(ProductLineItemCard::thumbHtml($product))),
            Forms\Components\Placeholder::make('cargo_name_'.$product->id)
                ->hiddenLabel()
                ->dehydrated(false)
                ->content(new HtmlString($nameHtml)),
            Forms\Components\Placeholder::make('cargo_code_'.$product->id)
                ->hiddenLabel()
                ->dehydrated(false)
                ->content(new HtmlString('<div class="fi-cargo-order-code">'.e($product->product_code ?? '—').'</div>')),
            Forms\Components\Placeholder::make('cargo_meta_'.$product->id)
                ->hiddenLabel()
                ->dehydrated(false)
                ->content(new HtmlString(
                    '<div class="fi-cargo-order-meta" title="'.e($formatMeters($stockMeters).' in stock').'">'
                    .'<span class="fi-cargo-order-meta-text">'.$metaHtml.' (</span>'
                    .$recommendedControl
                    .'<span class="fi-cargo-order-meta-text">)</span>'
                    .'</div>'
                )),
            Group::make([
                Forms\Components\TextInput::make('cargo_amounts.'.$product->id)
                    ->hiddenLabel()
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->default(0)
                        ->step(1)
                    ->afterStateHydrated(function (Forms\Components\TextInput $component, $state): void {
                        if ($state === null || $state === '') {
                            $component->state(0);
                        }
                    })
                    ->afterStateUpdated(function ($state, $livewire) use ($product): void {
                        if (method_exists($livewire, 'onCargoAmountInputUpdated')) {
                            $livewire->onCargoAmountInputUpdated((int) $product->id, (int) $state);
                        }
                    })
                    ->live(onBlur: true)
                    ->extraInputAttributes($amountInputAttributes)
                    ->extraFieldWrapperAttributes(['class' => 'fi-cargo-order-amount']),
                Group::make([
                    Forms\Components\Placeholder::make('cargo_cost_display_'.$product->id)
                        ->hiddenLabel()
                        ->dehydrated(false)
                        ->content(Money::format($product->collectionUnitCost()))
                        ->visibleOn('create'),
                    Forms\Components\TextInput::make('cargo_costs.'.$product->id)
                        ->hiddenLabel()
                        ->numeric()
                        ->minValue(0)
                        ->default($product->collectionUnitCost())
                        ->afterStateHydrated(function (Forms\Components\TextInput $component, $state) use ($product): void {
                            if ($state === null || $state === '') {
                                $component->state($product->collectionUnitCost());
                            }
                        })
                        ->step(0.01)
                        ->prefix(Money::prefix())
                        ->visibleOn('edit')
                        ->extraInputAttributes($costInputAttributes),
                ])
                    ->columns(1)
                    ->extraAttributes(['class' => 'fi-cargo-order-cost']),
            ])
                ->extraAttributes(['class' => 'fi-cargo-order-order-line']),
        ])
            ->columns(['default' => 5])
            ->extraAttributes([
                'class' => $rowClass,
                'data-search' => e($search),
                'data-stock-meters' => (string) $stockMeters,
                'data-recommended-meters' => (string) $recommended,
                'data-color-id' => (string) $colorId,
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
        $sizes = self::productSizeMeters();
        $total = 0.0;

        foreach ($amounts as $productId => $amount) {
            $amount = (int) $amount;
            if ($amount <= 0) {
                continue;
            }

            $id = (int) $productId;
            $unit = $unitCosts[$id] ?? $unitCosts[(string) $productId] ?? 0.0;
            $meters = $sizes[$id] ?? $sizes[(string) $productId] ?? null;

            $total += $meters !== null && $meters > 0
                ? $amount * $meters * $unit
                : $amount * $unit;
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
        self::$productSizeMeters = null;
    }

    public static function importCargoItemsToProductStock(Cargo $cargo): void
    {
        $cargo->unsetRelation('cargoItems');
        $cargo->load(['cargoItems.product.productType', 'cargoItems.product.color.collection', 'importTax']);

        foreach ($cargo->cargoItems as $item) {
            if ($item->amount <= 0 || $item->product_id === null) {
                continue;
            }

            $product = Product::query()
                ->with(['productType', 'color.collection'])
                ->lockForUpdate()
                ->find($item->product_id);

            if ($product === null) {
                continue;
            }

            $item->setRelation('product', $product);

            $incomingQty = CargoCostCalculator::incomingStockQuantity($item);
            $landedCost = CargoCostCalculator::landedStockUnitCost($cargo, $item);
            $oldQty = CargoCostCalculator::currentStockQuantity($product);
            $oldCost = $product->collectionUnitCost();
            $mergedCost = CargoCostCalculator::mergeAverageCost($oldCost, $oldQty, $landedCost, $incomingQty);

            $product->forceFill([
                'current_amount' => (int) $product->current_amount + (int) $item->amount,
                'cost' => $mergedCost,
            ])->save();
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function stripVirtualCargoFormFields(array $data): array
    {
        unset(
            $data['cargo_amounts'],
            $data['cargo_costs'],
            $data['cargo_line_filters'],
            $data['cargo_recommended'],
            $data['customs_document_uploads'],
            $data['customs_document_upload_names'],
            $data['customs_documents_list'],
            $data['customs_contact_missing'],
            $data['customs_email_sent_at_display'],
        );

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
            ->with('color.collection')
            ->get()
            ->mapWithKeys(fn (Product $product): array => [
                (int) $product->id => $product->collectionUnitCost(),
            ])
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
     * @return array<int, float|null>
     */
    public static function productSizeMeters(): array
    {
        if (self::$productSizeMeters !== null) {
            return self::$productSizeMeters;
        }

        self::$productSizeMeters = Product::query()
            ->with('productType')
            ->get(['id', 'name', 'product_type_id'])
            ->mapWithKeys(fn (Product $product): array => [
                (int) $product->id => $product->sizeMeters(),
            ])
            ->all();

        return self::$productSizeMeters;
    }

    /**
     * Default order amounts (rolls) for artificial-leather products below recommended stock.
     *
     * @return array<string, int> product_id => default_order
     */
    public static function lowStockDefaultOrderAmounts(int $supplierId): array
    {
        if ($supplierId <= 0) {
            return [];
        }

        $stockMeters = Product::stockMetersSqlExpression();

        $products = Product::query()
            ->artificialLeather()
            ->with('color')
            ->whereHas('color.collection', fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->whereHas('color', function (Builder $query): void {
                $query->where('recommended_stock', '>', 0)
                    ->where('default_order', '>', 0);
            })
            ->whereRaw("{$stockMeters} < (SELECT recommended_stock FROM colors WHERE colors.id = products.color_id)")
            ->get(['id', 'color_id']);

        $amounts = [];

        foreach ($products as $product) {
            $defaultOrder = (int) ($product->color?->default_order ?? 0);

            if ($defaultOrder <= 0) {
                continue;
            }

            $amounts[(string) $product->id] = $defaultOrder;
        }

        return $amounts;
    }

    /**
     * @return array<int>
     */
    public static function productIdsForSupplier(?int $supplierId): array
    {
        if (blank($supplierId)) {
            return [];
        }

        return Product::query()
            ->artificialLeather()
            ->whereHas('color.collection', fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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

        $defaultCosts = Product::collectionCostsByIds($productIds);

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
        self::$productSizeMeters = null;
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

                Tables\Columns\TextInputColumn::make('tracking')
                    ->label('Tracking')
                    ->placeholder('—')
                    ->searchable()
                    ->rules(['nullable', 'string', 'max:255'])
                    ->disabled(fn (Cargo $record): bool => $record->status === CargoStatus::Received),

                Tables\Columns\TextColumn::make('date_shipped')
                    ->date()
                    ->sortable()
                    ->label('Order date')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('estimated_arrival')
                    ->date()
                    ->sortable()
                    ->label('Estimated date'),

                Tables\Columns\TextColumn::make('actual_date')
                    ->date()
                    ->sortable()
                    ->label('Actual date')
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (CargoStatus $state): string => $state->getLabel() ?? $state->name)
                    ->sortable()
                    ->tooltip(fn (Cargo $record): string => $record->status === CargoStatus::Received
                        ? 'Received orders are read-only'
                        : 'Click to change status')
                    ->action(
                        Action::make('changeStatus')
                            ->visible(fn (Cargo $record): bool => $record->status !== CargoStatus::Received)
                            ->modalHeading('Update status')
                            ->modalWidth('md')
                            ->modalSubmitActionLabel('Update')
                            ->schema([
                                Forms\Components\Select::make('status')
                                    ->label('Status')
                    ->options(CargoStatus::class)
                                    ->required()
                                    ->native(false)
                                    ->live()
                                    ->helperText(function (Get $get, Cargo $record): ?string {
                                        $selected = $get('status');
                                        $selectedStatus = $selected instanceof CargoStatus
                                            ? $selected
                                            : CargoStatus::tryFrom((string) $selected);

                                        if (self::isTransitionToOrdered($selectedStatus, $record->status)) {
                                            return 'Optionally review and send the supplier email below.';
                                        }

                                        if ($selectedStatus === CargoStatus::Received && $record->status !== CargoStatus::Received) {
                                            return 'Stock will be imported into the warehouse.';
                                        }

                                        return null;
                                    })
                                    ->afterStateUpdated(function ($state, Set $set, Cargo $record): void {
                                        if (self::isTransitionToOrdered($state, $record->status)) {
                                            $set('email_body', self::emailPreviewForCargo($record));

                                            return;
                                        }

                                        $selectedStatus = $state instanceof CargoStatus
                                            ? $state
                                            : CargoStatus::tryFrom((string) $state);

                                        if ($selectedStatus === CargoStatus::Received && $record->status !== CargoStatus::Received) {
                                            $set('actual_date', Carbon::today()->toDateString());
                                        }

                                        $set('email_body', null);
                                    }),

                                Forms\Components\DatePicker::make('actual_date')
                                    ->label('Actual date')
                    ->native(false)
                                    ->required()
                                    ->default(fn (): string => Carbon::today()->toDateString())
                                    ->visible(function (Get $get, Cargo $record): bool {
                                        $selected = $get('status');
                                        $selectedStatus = $selected instanceof CargoStatus
                                            ? $selected
                                            : CargoStatus::tryFrom((string) $selected);

                                        return $selectedStatus === CargoStatus::Received
                                            && $record->status !== CargoStatus::Received;
                                    }),

                                Forms\Components\Textarea::make('email_body')
                                    ->label('Supplier email')
                                    ->rows(10)
                                    ->columnSpanFull()
                                    ->visible(fn (Get $get, Cargo $record): bool => self::isTransitionToOrdered($get('status'), $record->status)),
                            ])
                            ->fillForm(fn (Cargo $record): array => [
                                'status' => $record->status->value,
                                'actual_date' => $record->actual_date?->toDateString() ?: Carbon::today()->toDateString(),
                                'email_body' => '',
                            ])
                            ->extraModalFooterActions(fn (Action $action, array $data, Cargo $record): array => self::isTransitionToOrdered($data['status'] ?? null, $record->status)
                                ? [
                                    $action->makeModalSubmitAction('confirmAndSendEmail', arguments: ['sendEmail' => true])
                                        ->label('Update & send email')
                                        ->color('success'),
                                ]
                                : [])
                            ->action(function (Cargo $record, array $data, array $arguments): void {
                                $newStatus = ($data['status'] ?? null) instanceof CargoStatus
                                    ? $data['status']
                                    : CargoStatus::from((string) ($data['status'] ?? ''));
                                $oldStatus = $record->status;
                                $shouldSendEmail = (bool) ($arguments['sendEmail'] ?? false);
                                $emailBody = is_string($data['email_body'] ?? null) ? $data['email_body'] : null;

                                if ($newStatus === CargoStatus::Received) {
                                    try {
                                        CargoReceiver::receiveAndImport(
                                            $record,
                                            attributes: [
                                                'actual_date' => $data['actual_date'] ?? now()->toDateString(),
                                            ],
                                        );
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
                                        $emailBody,
                                    );

                                    Notification::make()
                                        ->title('Email sent to supplier')
                                        ->success()
                                        ->send();
                                } catch (Throwable $exception) {
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

                Tables\Columns\TextColumn::make('import_tax_amount')
                    ->label('Import tax')
                    ->state(fn (Cargo $record): float => CargoCostCalculator::importTaxesFor($record))
                    ->money(Money::currency())
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('full_cost')
                    ->label('Full cost')
                    ->state(fn (Cargo $record): float => CargoCostCalculator::fullCostWithShipping($record))
                    ->money(Money::currency())
                    ->placeholder('—'),
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
                Action::make('uploadCustomsInvoice')
                    ->label('Upload')
                    ->icon('heroicon-o-arrow-up-tray')
                    ->color('gray')
                    ->visible(fn (Cargo $record): bool => $record->status !== CargoStatus::Received)
                    ->modalHeading(fn (Cargo $record): string => 'Upload customs invoice — WO #'.$record->id)
                    ->modalSubmitActionLabel('Upload to SharePoint')
                    ->fillForm(fn (Cargo $record): array => [
                        'existing_url' => $record->customs_invoice_sharepoint_web_url,
                        'existing_name' => $record->customs_invoice_file_name,
                    ])
                    ->schema([
                        Forms\Components\Placeholder::make('existing_invoice')
                            ->label('Current file')
                            ->content(function (Get $get): HtmlString|string {
                                $url = $get('existing_url');
                                $name = $get('existing_name') ?: 'Open on SharePoint';

                                if (! filled($url)) {
                                    return 'No customs invoice uploaded yet.';
                                }

                                return new HtmlString(
                                    '<a href="'.e((string) $url).'" target="_blank" rel="noopener" class="text-primary-600 underline">'
                                    .e((string) $name)
                                    .'</a>'
                                );
                            })
                            ->visible(fn (Get $get): bool => filled($get('existing_url'))),
                        Forms\Components\FileUpload::make('customs_invoice')
                            ->label('Customs invoice')
                            ->required()
                            ->disk('public')
                            ->directory('cargo-customs-invoices')
                            ->maxSize(UploadLimits::MAX_KILOBYTES)
                            ->helperText(UploadLimits::note())
                            ->acceptedFileTypes([
                                'application/pdf',
                                'image/jpeg',
                                'image/png',
                                'image/webp',
                            ])
                            ->columnSpanFull(),
                    ])
                    ->action(function (Cargo $record, array $data): void {
                        $path = $data['customs_invoice'] ?? null;

                        if (! is_string($path) || $path === '') {
                            Notification::make()
                                ->title('No file selected')
                                ->warning()
                                ->send();

                            return;
                        }

                        try {
                            SharepointCustomsInvoiceUploader::ingestFromLocalPath($record, $path);
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Upload failed')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();

                            return;
                        }

                        if (! in_array($record->status, [CargoStatus::ImportFeePayed, CargoStatus::Received], true)) {
                            $record->update(['status' => CargoStatus::PayImportFee]);
                        }

                        ImportTaxPayment::syncFromCargo($record->fresh([
                            'cargoItems.product.productType',
                            'importTax',
                        ]));

                        Notification::make()
                            ->title('Customs invoice uploaded')
                            ->body('Stored on SharePoint. Import tax payment created under Finances → Import taxes.')
                            ->success()
                            ->send();
                    }),

                Action::make('payImportFee')
                    ->label('Pay')
                    ->icon('heroicon-o-banknotes')
                    ->color('primary')
                    ->visible(fn (Cargo $record): bool => $record->hasCustomsInvoice()
                        && ! in_array($record->status, [CargoStatus::ImportFeePayed, CargoStatus::Received], true))
                    ->requiresConfirmation()
                    ->modalHeading(fn (Cargo $record): string => 'Generate SEPA payment — WO #'.$record->id)
                    ->modalDescription('Downloads a SEPA pain.001 file for the customs import tax (and service cost if set), then marks the warehouse order as Import fee payed.')
                    ->modalSubmitActionLabel('Generate SEPA')
                    ->action(function (Cargo $record): ?StreamedResponse {
                        $payment = ImportTaxPayment::syncFromCargo($record->fresh([
                            'cargoItems.product.productType',
                            'importTax',
                        ]));

                        return ImportTaxPaymentResource::makeSepaPayments(
                            Collection::make([$payment]),
                        );
                    }),

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
            'supplier',
            'importTax',
            'importTaxPayment',
            'customsContact.mailTemplate',
            'cargoItems.product.productType',
        ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canEdit($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
    }

    public static function canDelete($record): bool
    {
        return auth()->user()?->canAccessNavigationGroup(static::$navigationGroup) ?? false;
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
