<?php

namespace App\Filament\Admin\Resources\Orders;

use App\Enums\CargoStatus;
use App\Enums\OrderStatus;
use App\Filament\Admin\Support\DoubleClickCellEdit;
use App\Filament\Admin\Support\ProductLineItemCard;
use App\Models\CargoItem;
use App\Models\Collection as CollectionModel;
use App\Models\Color;
use App\Models\Contact;
use App\Models\CustomerLevelPrice;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\OriginCountry;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\FulfillmentOrderMailer;
use App\Services\OrderInvoiceCreator;
use App\Services\OrderShippedMailer;
use App\Services\OrderStockAllocator;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Group;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Columns\TextInputColumn;
use Filament\Tables\Enums\FiltersLayout;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema as SchemaFacade;
use Illuminate\Support\HtmlString;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'Orders list';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'Orders';

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->options(OrderStatus::class)
                    ->required(fn (): bool => ! (auth()->user()?->hasRole('customer') ?? false))
                    ->native(false)
                    ->default(OrderStatus::Placed)
                    ->hidden(fn (): bool => auth()->user()?->hasRole('customer') ?? false),

                Select::make('user_id')
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

                DatePicker::make('order_date')
                    ->label('Date')
                    ->default(fn (): string => now()->toDateString())
                    ->visible(fn (): bool => SchemaFacade::hasColumn('orders', 'order_date')),

                Select::make('package_id')
                    ->label('Package profile')
                    ->options(fn (): array => self::packageProfileOptions())
                    ->default(fn (): ?int => self::defaultPackageProfileId())
                    ->native(false)
                    ->searchable()
                    ->live()
                    ->visible(fn (): bool => self::packageProfileOptions() !== [] && ! self::isCustomerUser()),

                View::make('filament.admin.components.order-package-summary')
                    ->viewData([
                        'packageProfiles' => self::packageProfiles(),
                        'initialPackageId' => self::defaultPackageProfileId(),
                        'productPackageIds' => self::productPackageIds(),
                        'resolvePackageFromProducts' => self::isCustomerUser(),
                    ])
                    ->columnSpanFull()
                    ->visible(fn (): bool => self::packageProfiles() !== []),

                View::make('filament.admin.components.order-line-section-totals')
                    ->columnSpanFull(),

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

    private static function isCustomerUser(): bool
    {
        return auth()->user()?->hasRole('customer') ?? false;
    }

    /**
     * @return array<int, int> product id => package id
     */
    private static function productPackageIds(): array
    {
        return Product::query()
            ->with(['color.collection.supplier:id,default_package_id'])
            ->get(['id', 'color_id'])
            ->mapWithKeys(function (Product $product): array {
                $packageId = $product->color?->collection?->supplier?->default_package_id;

                return filled($packageId) ? [(int) $product->id => (int) $packageId] : [];
            })
            ->all();
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     */
    public static function packageIdFromAmounts(array $amounts): ?int
    {
        $normalized = self::normalizeAmounts($amounts);

        if ($normalized === []) {
            return self::defaultPackageProfileId();
        }

        $products = Product::query()
            ->with(['color.collection.supplier:id,default_package_id'])
            ->whereIn('id', array_keys($normalized))
            ->get()
            ->keyBy('id');

        $votes = [];

        foreach ($normalized as $productId => $quantity) {
            $packageId = $products->get($productId)?->color?->collection?->supplier?->default_package_id;

            if (! filled($packageId)) {
                continue;
            }

            $votes[(int) $packageId] = ($votes[(int) $packageId] ?? 0) + $quantity;
        }

        if ($votes === []) {
            return self::defaultPackageProfileId();
        }

        arsort($votes);

        return (int) array_key_first($votes);
    }

    /**
     * Customer select: "Company name (First name Last name)" — space between name and surname, no comma.
     */
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
     * @return array<int, Section>
     */
    private static function orderLineItemSections(): array
    {
        $groups = [];

        foreach (
            CollectionModel::query()
                ->orderBy('name')
                ->with(['products' => fn ($query) => $query->with(['productType', 'color.collection'])->orderBy('color_id')])
                ->get() as $collection
        ) {
            $products = $collection->products
                ->sortBy(fn (Product $product): string => $product->color?->color_code ?? '')
                ->values();

            if ($products->isEmpty()) {
                continue;
            }

            $groups[] = self::makeOrderCatalogCollectionGroup(
                sectionKey: 'collection-'.$collection->id,
                heading: self::collectionSectionHeading($collection),
                products: $products->all(),
                upcomingByProductId: self::upcomingCargoByProductIds($products->pluck('id')->all()),
            );
        }

        $catalogGroup = self::catalogOrderCatalogGroup();

        if ($catalogGroup !== null) {
            $groups[] = $catalogGroup;
        }

        return [
            Section::make('Products')
                ->description('Collections start collapsed. Search or Expand all to browse — amounts sync without loading every product as a Livewire field. Low-stock colors are highlighted (recommended stock is read-only here).')
                ->columnSpanFull()
                ->compact()
                ->extraAttributes([
                    'class' => 'fi-cargo-order-wrap fi-cargo-order-wrap--sales',
                    'wire:ignore' => true,
                ])
                ->schema([
                    View::make('filament.admin.components.cargo-order-table-chrome')
                        ->viewData([
                            'headings' => ['', 'Color / product', 'Code', 'Size (m)', 'Stock', 'Upcoming', 'Amount'],
                        ])
                        ->columnSpanFull(),
                    ...$groups,
                ]),
        ];
    }

    private static function catalogOrderCatalogGroup(): ?View
    {
        $products = Product::query()
            ->catalog()
            ->with('productType')
            ->orderBy('name')
            ->get();

        if ($products->isEmpty()) {
            return null;
        }

        $typeName = $products->first()?->productType?->name ?? 'Catalog';

        return self::makeOrderCatalogCollectionGroup(
            sectionKey: 'catalog',
            heading: $typeName,
            products: $products->all(),
            upcomingByProductId: self::upcomingCargoByProductIds($products->pluck('id')->all()),
        );
    }

    /**
     * @param  list<Product>  $products
     * @param  array<int, array{amount: int, estimated_arrival: string|null}>  $upcomingByProductId
     */
    private static function makeOrderCatalogCollectionGroup(
        string $sectionKey,
        string $heading,
        array $products,
        array $upcomingByProductId,
    ): View {
        $productIds = array_values(array_map(
            fn (Product $product): int => (int) $product->id,
            $products,
        ));
        $colorStockMetersById = self::colorStockMetersByColorId($products);

        return View::make('filament.admin.components.order-catalog-collection')
            ->key('order-line-section-'.$sectionKey)
            ->columnSpanFull()
            ->viewData(function (?Order $record, Get $get) use ($sectionKey, $heading, $products, $upcomingByProductId, $productIds, $colorStockMetersById): array {
                $amounts = is_array($get('order_amounts')) ? $get('order_amounts') : [];

                return [
                    'sectionKey' => $sectionKey,
                    'heading' => $heading,
                    'productIds' => $productIds,
                    'rows' => array_map(
                        fn (Product $product): array => self::orderCatalogRowData(
                            $product,
                            $heading,
                            $upcomingByProductId[$product->id] ?? null,
                            $record,
                            $amounts,
                            $colorStockMetersById,
                        ),
                        $products,
                    ),
                ];
            });
    }

    /**
     * Color-level stock meters (matches Reports → Low stock), keyed by color id.
     *
     * @param  list<Product>  $products
     * @return array<int, float>
     */
    public static function colorStockMetersByColorId(array $products): array
    {
        $colorIds = [];
        foreach ($products as $product) {
            if ($product->isCatalog()) {
                continue;
            }

            $colorId = (int) ($product->color_id ?? 0);
            if ($colorId > 0) {
                $colorIds[$colorId] = true;
            }
        }

        return Color::stockMetersByIds(array_keys($colorIds));
    }

    /**
     * @param  array{amount: int, estimated_arrival: string|null}|null  $upcoming
     * @param  array<string|int, mixed>  $amounts
     * @param  array<int, float>  $colorStockMetersById
     * @return array<string, mixed>
     */
    private static function orderCatalogRowData(
        Product $product,
        string $collectionName,
        ?array $upcoming,
        ?Order $record,
        array $amounts,
        array $colorStockMetersById = [],
    ): array {
        $search = strtolower(trim(implode(' ', array_filter([
            $collectionName,
            $product->color?->color_name,
            $product->color?->color_code,
            $product->product_code,
            $product->alternative_code,
            $product->name,
        ], fn ($value): bool => filled($value)))));

        $sizeLabel = $product->sizeMeters() !== null
            ? rtrim(rtrim(number_format((float) $product->name, 2, '.', ''), '0'), '.').' m'
            : '—';

        $nameHtml = $product->isCatalog()
            ? '<div class="fi-cargo-order-name">'.e($product->name ?: '—').'</div>'
            : '<div class="fi-cargo-order-name">'.e($product->color?->color_name ?? '—').'</div>';

        $upcomingAmount = (int) ($upcoming['amount'] ?? 0);

        $upcomingHtml = '<div class="fi-cargo-order-upcoming">—</div>';
        if ($upcoming !== null) {
            $date = filled($upcoming['estimated_arrival'] ?? null)
                ? e(Carbon::parse((string) $upcoming['estimated_arrival'])->format('Y-m-d'))
                : '—';
            $upcomingHtml = '<div class="fi-cargo-order-upcoming fi-order-product-upcoming">'.$upcomingAmount.' · Est. '.$date.'</div>';
        }

        $colorId = (int) ($product->color_id ?? 0);
        $recommended = (int) ($product->color?->recommended_stock ?? 0);
        $stockMeters = $product->stockMeters();
        $sizeMeters = $product->sizeMeters();
        // Recommended stock is per color — compare color totals, not each size SKU alone.
        $colorStockMeters = $colorId > 0
            ? (float) ($colorStockMetersById[$colorId] ?? $product->color?->stockMeters() ?? $stockMeters)
            : $stockMeters;
        $isLowStock = ! $product->isCatalog() && $colorId > 0 && $recommended > 0 && $colorStockMeters < $recommended;

        $formatMeters = static function (float $meters): string {
            return rtrim(rtrim(number_format($meters, 2, '.', ''), '0'), '.').'m';
        };

        $displayStockRolls = (int) $product->current_amount;
        $stockHtml = e((string) $displayStockRolls);
        if (! $product->isCatalog() && $sizeMeters !== null) {
            $recommendedControl = $colorId > 0
                ? '<span class="fi-cargo-order-rec-btn is-disabled"'
                    .' data-recommended-stock="1"'
                    .' data-color-id="'.$colorId.'"'
                    .' data-recommended-value="'.$recommended.'"'
                    .' title="Recommended stock / low-stock benchmark (read-only)">'
                    .'<span data-rec-label>'.$recommended.'</span> m'
                    .'</span>'
                : '<span class="fi-cargo-order-rec-btn is-disabled">'.$recommended.' m</span>';

            $stockHtml = '<div class="fi-cargo-order-meta" title="'.e($formatMeters($stockMeters).' in stock').'">'
                .'<span class="fi-cargo-order-meta-text">'
                .e($formatMeters($sizeMeters)).' × '.e((string) $displayStockRolls).' = '
                .'<strong class="fi-cargo-order-meta-total">'.e($formatMeters($stockMeters)).'</strong>'
                .' (</span>'
                .$recommendedControl
                .'<span class="fi-cargo-order-meta-text">)</span>'
                .'</div>';
        }

        $amount = (int) ($amounts[(string) $product->id] ?? $amounts[$product->id] ?? 0);
        $max = self::availableOrderAmount(
            $product,
            $upcomingAmount,
            self::reservedAmountOnOrder($record, (int) $product->id),
        );

        return [
            'product_id' => (int) $product->id,
            'search' => $search,
            // data-stock-meters drives JS low-stock class — must be color total.
            'stock_meters' => $colorStockMeters,
            'recommended' => $recommended,
            'color_id' => $colorId,
            'is_low_stock' => $isLowStock,
            'thumb_html' => ProductLineItemCard::thumbHtml($product),
            'name_html' => $nameHtml,
            'code_html' => '<div class="fi-cargo-order-code">'.e($product->product_code ?? '—').'</div>',
            'size_label' => $sizeLabel,
            'stock_html' => $stockHtml,
            'upcoming_html' => $upcomingHtml,
            'amount' => $amount,
            'max' => $max,
        ];
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

    public static function reservedAmountOnOrder(?Order $order, int $productId): int
    {
        if ($order === null || ! $order->stock_reserved) {
            return 0;
        }

        $order->loadMissing('orderItems');

        foreach ($order->orderItems as $item) {
            if ((int) $item->product_id === $productId) {
                return (int) $item->amount;
            }
        }

        return 0;
    }

    public static function availableOrderAmount(Product $product, int $upcomingAmount = 0, int $reservedByThisOrder = 0): int
    {
        return max(0, (int) $product->current_amount + max(0, $reservedByThisOrder) + max(0, $upcomingAmount));
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     * @return array<int, int>
     */
    public static function clampAmountsToAvailability(array $amounts): array
    {
        $normalized = self::normalizeAmounts($amounts);

        if ($normalized === []) {
            return [];
        }

        $productIds = array_keys($normalized);
        $stocks = Product::query()
            ->whereIn('id', $productIds)
            ->pluck('current_amount', 'id');
        $upcoming = self::upcomingCargoByProductIds($productIds);

        $clamped = [];

        foreach ($normalized as $productId => $amount) {
            $max = max(0, (int) ($stocks[$productId] ?? 0) + (int) ($upcoming[$productId]['amount'] ?? 0));
            $amount = min($amount, $max);

            if ($amount > 0) {
                $clamped[$productId] = $amount;
            }
        }

        return $clamped;
    }

    /**
     * @param  array<string|int, mixed>  $amounts
     */
    public static function syncOrderItemsFromAmounts(Order $order, array $amounts): void
    {
        $wasReserved = (bool) $order->stock_reserved;

        if ($wasReserved) {
            OrderStockAllocator::release($order);
        }

        $order->orderItems()->delete();

        foreach (self::clampAmountsToAvailability($amounts) as $productId => $amount) {
            $order->orderItems()->create([
                'product_id' => $productId,
                'amount' => $amount,
            ]);
        }

        if ($wasReserved) {
            $order->unsetRelation('orderItems');
            OrderStockAllocator::reserve($order);
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
                $total += self::catalogLineTotal($product->collectionUnitPrice(), $amount);

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
        return Product::lineTotalForSize($unitPrice, $size, $quantity);
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
                    $unit = $product->collectionUnitPrice();

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

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('user.company_name')
                        ->label('Customer')
                        ->searchable()
                        ->sortable()
                        ->placeholder('—'),
                    'customer_name',
                    fn (Order $record): bool => $record->user_id !== null,
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('order_date')
                        ->label('Order date')
                        ->date()
                        ->sortable()
                        ->placeholder('—'),
                    'order_date',
                ),

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
                                Select::make('status')
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

                Tables\Columns\TextColumn::make('payment_status')
                    ->label('Payment status')
                    ->badge()
                    ->getStateUsing(fn (Order $record): string => $record->paymentSettlementLabel())
                    ->color(fn (Order $record): string => $record->paymentSettlementColor())
                    ->tooltip(function (Order $record): ?string {
                        $invoices = $record->invoiceCollection();
                        if ($invoices->isEmpty()) {
                            return 'No invoice linked to this order';
                        }

                        return $invoices
                            ->map(fn ($invoice): string => trim((string) ($invoice->invoice_number ?: '#'.$invoice->id))
                                .' · '.$invoice->paymentSettlementLabel())
                            ->implode("\n");
                    })
                    ->action(
                        Action::make('changePaymentStatus')
                            ->modalHeading('Change payment status')
                            ->modalDescription(fn (Order $record): string => $record->invoiceCollection()->isEmpty()
                                ? 'This order has no linked invoice yet.'
                                : 'Updates paid amount on linked invoice(s).')
                            ->disabled(fn (Order $record): bool => $record->invoiceCollection()->isEmpty())
                            ->schema([
                                Select::make('payment_status')
                                    ->label('Payment status')
                                    ->options([
                                        'paid' => 'Paid',
                                        'partly_paid' => 'Partly paid',
                                        'unpaid' => 'Not paid',
                                    ])
                                    ->required()
                                    ->native(false)
                                    ->live(),

                                Forms\Components\TextInput::make('paid_amount')
                                    ->label('Paid amount')
                                    ->numeric()
                                    ->minValue(0)
                                    ->step(0.01)
                                    ->prefix(Money::prefix())
                                    ->required(fn (Get $get): bool => $get('payment_status') === 'partly_paid')
                                    ->visible(fn (Get $get): bool => $get('payment_status') === 'partly_paid')
                                    ->helperText(fn (Order $record): string => 'Invoice total: '
                                        .Money::format($record->invoiceCollection()->sum(fn (Invoice $invoice): float => (float) $invoice->sum_inc_vat))),
                            ])
                            ->fillForm(function (Order $record): array {
                                $status = $record->paymentSettlementStatus() ?? 'unpaid';
                                $paid = round($record->invoiceCollection()->sum(
                                    fn (Invoice $invoice): float => (float) ($invoice->paid_amount ?? 0),
                                ), 2);

                                return [
                                    'payment_status' => $status,
                                    'paid_amount' => $paid,
                                ];
                            })
                            ->modalSubmitActionLabel('Confirm')
                            ->action(function (Order $record, array $data): void {
                                $invoices = $record->invoiceCollection();
                                if ($invoices->isEmpty()) {
                                    Notification::make()
                                        ->title('No invoice linked')
                                        ->body('Create or link an invoice before changing payment status.')
                                        ->warning()
                                        ->send();

                                    return;
                                }

                                $status = (string) ($data['payment_status'] ?? 'unpaid');

                                if ($status === 'paid') {
                                    foreach ($invoices as $invoice) {
                                        $invoice->markFullyPaid();
                                    }

                                    Notification::make()
                                        ->title('Marked as paid')
                                        ->success()
                                        ->send();

                                    return;
                                }

                                if ($status === 'unpaid') {
                                    foreach ($invoices as $invoice) {
                                        $invoice->markUnpaid();
                                    }

                                    Notification::make()
                                        ->title('Marked as not paid')
                                        ->success()
                                        ->send();

                                    return;
                                }

                                $paidAmount = max(0, round((float) ($data['paid_amount'] ?? 0), 2));
                                $remaining = $paidAmount;

                                foreach ($invoices as $invoice) {
                                    $total = round((float) ($invoice->sum_inc_vat ?? 0), 2);
                                    $allocate = min($total, $remaining);

                                    $invoice->forceFill([
                                        'paid_amount' => $allocate,
                                        'paid' => false,
                                    ]);
                                    $invoice->normalizePaymentState();
                                    $invoice->save();

                                    $remaining = max(0, round($remaining - $allocate, 2));
                                }

                                Notification::make()
                                    ->title('Payment status updated')
                                    ->body('Paid amount set to '.Money::format($paidAmount).'.')
                                    ->success()
                                    ->send();
                            }),
                    ),

                Tables\Columns\TextColumn::make('invoices')
                    ->label('Invoice')
                    ->getStateUsing(function (Order $record): HtmlString|string {
                        $invoices = $record->invoiceCollection();
                        if ($invoices->isEmpty()) {
                            return '—';
                        }

                        $links = $invoices
                            ->map(fn ($invoice): string => $invoice->openDocumentLinksHtml())
                            ->all();

                        return new HtmlString(implode('<br>', $links));
                    })
                    ->html()
                    ->placeholder('—')
                    ->toggleable(),

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
                SelectFilter::make('contact_id')
                    ->label('Customer')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return;
                        }

                        $query->whereHas(
                            'invoices',
                            fn (Builder $invoiceQuery) => $invoiceQuery->where('contact_id', $value),
                        );
                    }),

                SelectFilter::make('customer_market')
                    ->label('Market')
                    ->options([
                        'lithuania' => 'Lithuania',
                        'export' => 'Export',
                    ])
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = $data['value'] ?? null;
                        if ($value === 'lithuania') {
                            $query->whereHas('user', fn (Builder $user) => $user->lithuaniaMarket());

                            return;
                        }

                        if ($value === 'export') {
                            $query->whereHas('user', fn (Builder $user) => $user->exportMarket());
                        }
                    }),

                SelectFilter::make('customer_country')
                    ->label('Country')
                    ->options(fn (): array => OriginCountry::options())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = $data['value'] ?? null;

                        if (blank($value)) {
                            return;
                        }

                        $country = trim((string) $value);

                        $query->where(function (Builder $builder) use ($country): void {
                            $builder
                                ->whereHas('user', function (Builder $user) use ($country): void {
                                    $user->whereRaw('LOWER(TRIM(company_country)) = ?', [mb_strtolower($country)]);
                                })
                                ->orWhereHas('invoices.contact', function (Builder $contact) use ($country): void {
                                    $contact->whereRaw('LOWER(TRIM(company_country)) = ?', [mb_strtolower($country)]);
                                });
                        });
                    }),

                SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options([
                        'paid' => 'Paid',
                        'partly_paid' => 'Partly paid',
                        'unpaid' => 'Not paid',
                    ])
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = $data['value'] ?? null;
                        if (blank($value)) {
                            return;
                        }

                        $fullyPaid = function (Builder $invoiceQuery): void {
                            $invoiceQuery->where(function (Builder $builder): void {
                                $builder
                                    ->where('paid', true)
                                    ->orWhereRaw('COALESCE(sum_inc_vat, 0) > 0.004 AND ABS(COALESCE(paid_amount, 0) - sum_inc_vat) < 0.005');
                            });
                        };

                        $query->whereHas('invoices', function (Builder $invoiceQuery) use ($value, $fullyPaid): void {
                            if ($value === 'paid') {
                                $fullyPaid($invoiceQuery);

                                return;
                            }

                            if ($value === 'partly_paid') {
                                $invoiceQuery
                                    ->whereRaw('COALESCE(paid_amount, 0) > 0.004')
                                    ->where(function (Builder $builder) use ($fullyPaid): void {
                                        $builder->whereNot(fn (Builder $inner) => $fullyPaid($inner));
                                    });

                                return;
                            }

                            if ($value === 'unpaid') {
                                $invoiceQuery
                                    ->whereRaw('COALESCE(paid_amount, 0) <= 0.004')
                                    ->where(function (Builder $builder) use ($fullyPaid): void {
                                        $builder->whereNot(fn (Builder $inner) => $fullyPaid($inner));
                                    });
                            }
                        });
                    }),

                SelectFilter::make('month')
                    ->label('Month')
                    ->placeholder('All months')
                    ->options(fn (): array => self::orderMonthFilterOptions())
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = trim((string) ($data['value'] ?? ''));
                        if ($value === '' || $value === 'any') {
                            return;
                        }

                        if (preg_match('/^\d{4}-\d{2}$/', $value) !== 1) {
                            return;
                        }

                        [$year, $month] = array_map('intval', explode('-', $value));
                        $start = Carbon::create($year, $month, 1)->startOfDay();
                        $end = $start->copy()->endOfMonth();

                        $query->whereRaw(
                            'COALESCE(DATE(order_date), DATE(created_at)) BETWEEN ? AND ?',
                            [$start->toDateString(), $end->toDateString()],
                        );
                    }),

                Filter::make('invoice_dates')
                    ->label('Invoice dates')
                    ->schema([
                        DatePicker::make('from')
                            ->label('From')
                            ->native(false),
                        DatePicker::make('until')
                            ->label('Until')
                            ->native(false),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(function (Builder $query, array $data): void {
                        if (filled($data['from'] ?? null)) {
                            $query->whereHas(
                                'invoices',
                                fn (Builder $invoiceQuery) => $invoiceQuery->whereDate('invoice_date', '>=', $data['from']),
                            );
                        }

                        if (filled($data['until'] ?? null)) {
                            $query->whereHas(
                                'invoices',
                                fn (Builder $invoiceQuery) => $invoiceQuery->whereDate('invoice_date', '<=', $data['until']),
                            );
                        }
                    }),

                Filter::make('finance')
                    ->label('Type / category')
                    ->schema([
                        Select::make('finance_kind')
                            ->label('Type')
                            ->options([
                                'income' => 'Income',
                                'expense' => 'Expense',
                                Invoice::OWN_ACCOUNT_FILTER => Invoice::OWN_ACCOUNT_LABEL,
                                'missing' => 'Missing type',
                            ])
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                                if ($state === 'missing') {
                                    $set('finance_type', null);

                                    return;
                                }

                                if ($state === Invoice::OWN_ACCOUNT_FILTER) {
                                    $set('finance_type', Invoice::OWN_ACCOUNT_FILTER);

                                    return;
                                }

                                $type = $get('finance_type');
                                if (! filled($type) || $state === null) {
                                    return;
                                }

                                if ($type === Invoice::OWN_ACCOUNT_FILTER) {
                                    $set('finance_type', null);

                                    return;
                                }

                                $prefix = str_starts_with((string) $type, 'income:') ? 'income' : 'expense';
                                if ($state !== $prefix) {
                                    $set('finance_type', null);
                                }
                            }),

                        Select::make('finance_type')
                            ->label('Category')
                            ->options(fn (): array => Invoice::financeCategorySelectOptions())
                            ->searchable()
                            ->preload()
                            ->placeholder('All')
                            ->native(false)
                            ->live()
                            ->visible(fn (Get $get): bool => ($get('finance_kind') ?? null) !== 'missing')
                            ->afterStateUpdated(function (?string $state, Set $set): void {
                                if (! filled($state)) {
                                    return;
                                }

                                if ($state === Invoice::OWN_ACCOUNT_FILTER) {
                                    $set('finance_kind', Invoice::OWN_ACCOUNT_FILTER);

                                    return;
                                }

                                $set(
                                    'finance_kind',
                                    str_starts_with($state, 'income:') ? 'income' : 'expense',
                                );
                            }),
                    ])
                    ->columns(2)
                    ->columnSpan(2)
                    ->query(function (Builder $query, array $data): void {
                        $financeKind = $data['finance_kind'] ?? null;
                        $financeType = $data['finance_type'] ?? null;

                        if (blank($financeKind) && blank($financeType)) {
                            return;
                        }

                        $query->whereHas('invoices', function (Builder $invoiceQuery) use ($financeKind, $financeType): void {
                            self::applyInvoiceFinanceFilter($invoiceQuery, $financeKind, $financeType);
                        });
                    }),

                SelectFilter::make('private_provider')
                    ->label('Private provider')
                    ->options([
                        '1' => 'Yes',
                        '0' => 'No',
                    ])
                    ->native(false)
                    ->query(function (Builder $query, array $data): void {
                        $value = $data['value'] ?? null;
                        if ($value === null || $value === '') {
                            return;
                        }

                        $query->whereHas(
                            'invoices',
                            fn (Builder $invoiceQuery) => $invoiceQuery->where('private_provider', (bool) (int) $value),
                        );
                    }),
            ])
            ->filtersLayout(FiltersLayout::AboveContent)
            ->filtersFormColumns(4)
            ->deferFilters(false)
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

    /**
     * @return array<string, string>
     */
    private static function orderMonthFilterOptions(): array
    {
        $connection = Order::query()->getConnection();
        $monthExpression = $connection->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', COALESCE(order_date, created_at))"
            : "DATE_FORMAT(COALESCE(order_date, created_at), '%Y-%m')";

        $keys = Order::query()
            ->selectRaw("{$monthExpression} as month_key")
            ->groupBy('month_key')
            ->orderByDesc('month_key')
            ->pluck('month_key')
            ->filter(fn ($key): bool => is_string($key) && preg_match('/^\d{4}-\d{2}$/', $key) === 1)
            ->values()
            ->all();

        if ($keys === []) {
            $keys = [now()->format('Y-m')];
        }

        $options = [];
        foreach ($keys as $key) {
            $options[(string) $key] = Carbon::createFromFormat('Y-m', (string) $key)->format('Y F');
        }

        return $options;
    }

    private static function applyInvoiceFinanceFilter(Builder $invoiceQuery, mixed $financeKind, mixed $financeType): void
    {
        if (filled($financeType) && is_string($financeType)) {
            if ($financeType === Invoice::OWN_ACCOUNT_FILTER) {
                $invoiceQuery->where('is_own_account', true);

                return;
            }

            if (str_starts_with($financeType, 'income:')) {
                $invoiceQuery->where('income_type_id', (int) substr($financeType, strlen('income:')))
                    ->where('is_own_account', false);

                return;
            }

            if (str_starts_with($financeType, 'expense:')) {
                $invoiceQuery->where('expense_type_id', (int) substr($financeType, strlen('expense:')))
                    ->where('is_own_account', false);

                return;
            }
        }

        if ($financeKind === 'income') {
            $invoiceQuery->whereNotNull('income_type_id')->where('is_own_account', false);

            return;
        }

        if ($financeKind === 'expense') {
            $invoiceQuery->whereNotNull('expense_type_id')->where('is_own_account', false);

            return;
        }

        if ($financeKind === Invoice::OWN_ACCOUNT_FILTER) {
            $invoiceQuery->where('is_own_account', true);

            return;
        }

        if ($financeKind === 'missing') {
            $invoiceQuery
                ->where('is_own_account', false)
                ->whereNull('income_type_id')
                ->whereNull('expense_type_id');
        }
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with([
            'user',
            'orderItems.product.color.collection',
            'invoices',
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
            'index' => Pages\ListOrders::route('/'),
            'create' => Pages\CreateOrder::route('/create'),
            'edit' => Pages\EditOrder::route('/{record}/edit'),
        ];
    }
}
