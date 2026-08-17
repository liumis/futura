<?php

namespace App\Filament\Admin\Resources\ManualImports;

use App\Filament\Admin\Resources\ManualImports\Pages;
use App\Models\Contact;
use App\Models\ManualImport;
use App\Models\Product;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use UnitEnum;

class ManualImportResource extends Resource
{
    protected static ?string $model = ManualImport::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-arrow-up-tray';

    protected static ?string $navigationLabel = 'Manual import';

    protected static ?string $modelLabel = 'Manual import';

    protected static ?string $pluralModelLabel = 'Manual imports';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('product_id')
                    ->label('Product')
                    ->relationship(
                        'product',
                        'product_code',
                        function (Builder $query, ?string $search): Builder {
                            $query
                                ->with(['productType', 'color.collection'])
                                ->orderBy('product_code');

                            if (blank($search)) {
                                return $query;
                            }

                            $term = '%'.$search.'%';

                            return $query->where(function (Builder $inner) use ($term): void {
                                $inner
                                    ->where('product_code', 'like', $term)
                                    ->orWhere('name', 'like', $term)
                                    ->orWhereHas('color', function (Builder $color) use ($term): void {
                                        $color
                                            ->where('color_name', 'like', $term)
                                            ->orWhere('color_code', 'like', $term);
                                    })
                                    ->orWhereHas('color.collection', function (Builder $collection) use ($term): void {
                                        $collection->where('name', 'like', $term);
                                    })
                                    ->orWhereHas('productType', function (Builder $type) use ($term): void {
                                        $type->where('name', 'like', $term);
                                    });
                            });
                        },
                    )
                    ->getOptionLabelFromRecordUsing(
                        function (Product $product): string {
                            if ($product->isCatalog()) {
                                return sprintf(
                                    '%s · %s (%s) · stock %d',
                                    $product->productType?->name ?? 'Catalog',
                                    $product->name ?? '—',
                                    $product->product_code,
                                    (int) $product->current_amount,
                                );
                            }

                            return sprintf(
                                '%s · %s (%s) · stock %d',
                                $product->color?->collection?->name ?? '—',
                                $product->color?->color_name ?? '—',
                                $product->product_code,
                                (int) $product->current_amount,
                            );
                        },
                    )
                    ->searchable([])
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set): void {
                        if (blank($state)) {
                            return;
                        }

                        $price = Product::query()->whereKey($state)->value('default_cost');

                        if ($price !== null) {
                            $set('price', (float) $price);
                        }
                    }),

                Forms\Components\TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->integer()
                    ->required()
                    ->minValue(1)
                    ->default(1)
                    ->helperText('Units added to product stock.'),

                Forms\Components\TextInput::make('price')
                    ->label('Price')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->helperText('Updates the product price.'),

                Forms\Components\DatePicker::make('imported_at')
                    ->label('Import date')
                    ->native(false)
                    ->default(now()->toDateString())
                    ->required(),

                Forms\Components\FileUpload::make('invoice_path')
                    ->label('Invoice')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png'])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('invoices')
                    ->downloadable()
                    ->openable()
                    ->live()
                    ->helperText(UploadLimits::withExistingNote('Optional. If attached, the invoice is also added to Invoices (PDF, JPG, PNG).'))
                    ->columnSpanFull(),

                Forms\Components\Select::make('contact_id')
                    ->label('Company')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->required(fn (Get $get): bool => filled($get('invoice_path')))
                    ->visible(fn (Get $get): bool => filled($get('invoice_path')))
                    ->helperText('Required when an invoice file is attached. Used on the Invoices list.'),

                Forms\Components\Textarea::make('note')
                    ->label('Note')
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('imported_at')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('product.product_code')
                    ->label('Product')
                    ->searchable()
                    ->sortable()
                    ->description(function (ManualImport $record): ?string {
                        $product = $record->product;

                        if ($product === null) {
                            return null;
                        }

                        if ($product->isCatalog()) {
                            return $product->name;
                        }

                        $collection = $product->color?->collection?->name;
                        $color = $product->color?->color_name;

                        return trim(implode(' · ', array_filter([$collection, $color]))) ?: null;
                    }),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('price')
                    ->label('Price')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->alignEnd()
                    ->sortable(),

                Tables\Columns\TextColumn::make('contact.company_name')
                    ->label('Company')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\IconColumn::make('invoice_path')
                    ->label('Invoice')
                    ->alignCenter()
                    ->icon(fn (?string $state): string => filled($state)
                        ? 'heroicon-o-check-circle'
                        : 'heroicon-o-x-circle')
                    ->color(fn (?string $state): string => filled($state) ? 'success' : 'danger')
                    ->url(fn (ManualImport $record): ?string => filled($record->invoice_path)
                        ? Storage::disk('public')->url($record->invoice_path)
                        : null)
                    ->openUrlInNewTab()
                    ->tooltip(fn (?string $state): string => filled($state) ? 'Open invoice' : 'No invoice attached'),

                Tables\Columns\TextColumn::make('invoice_id')
                    ->label('Invoices #')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('By')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('note')
                    ->label('Note')
                    ->limit(40)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('imported_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('product_id')
                    ->label('Product')
                    ->relationship('product', 'product_code')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->paginationPageOptions([20, 50, 100])
            ->defaultPaginationPageOption(20);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['product.productType', 'product.color.collection', 'user', 'contact', 'invoice']);
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
            'index' => Pages\ListManualImports::route('/'),
            'create' => Pages\CreateManualImport::route('/create'),
            'edit' => Pages\EditManualImport::route('/{record}/edit'),
        ];
    }
}
