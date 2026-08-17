<?php

namespace App\Filament\Customer\Resources\Orders;

use App\Enums\OrderStatus;
use App\Filament\Admin\Resources\Orders\OrderResource as AdminOrderResource;
use App\Filament\Customer\Resources\Orders\Pages;
use App\Models\Order;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-list';

    protected static ?string $navigationLabel = 'My orders';

    protected static ?string $modelLabel = 'Order';

    protected static ?string $pluralModelLabel = 'My orders';

    protected static string|UnitEnum|null $navigationGroup = 'Orders';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return AdminOrderResource::form($schema);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')
                    ->label('Order #')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Total')
                    ->formatStateUsing(fn ($state): string => Money::format($state))
                    ->tooltip(function (Order $record): ?HtmlString {
                        $html = AdminOrderResource::buildOrderLinesTooltipHtml($record);

                        return $html !== null ? new HtmlString($html) : null;
                    })
                    ->sortable(),

                Tables\Columns\TextColumn::make('tracking_number')
                    ->label('Tracking')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('order_date')
                    ->label('Order date')
                    ->dateTime()
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('id', 'desc')
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
                EditAction::make()
                    ->visible(fn (Order $record): bool => $record->status === OrderStatus::Pending),
            ])
            ->toolbarActions([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->where('user_id', auth()->id())
            ->with([
                'user',
                'orderItems.product.color.collection',
            ]);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasRole('customer') ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->hasRole('customer') ?? false;
    }

    public static function canEdit($record): bool
    {
        if (! (auth()->user()?->hasRole('customer') ?? false)) {
            return false;
        }

        return (int) $record->user_id === (int) auth()->id()
            && $record->status === OrderStatus::Pending;
    }

    public static function canDelete($record): bool
    {
        return false;
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
