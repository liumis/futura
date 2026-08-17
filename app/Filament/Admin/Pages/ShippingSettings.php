<?php

namespace App\Filament\Admin\Pages;

use App\Models\MailTemplate;
use App\Models\ShippingSetting;
use App\Support\Money;
use BackedEnum;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Pages\Page;
use Filament\Tables;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use UnitEnum;

class ShippingSettings extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-cog-8-tooth';

    protected static ?string $navigationLabel = 'Shipping settings';

    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';

    protected static ?int $navigationSort = 3;

    protected string $view = 'filament.admin.pages.shipping-settings';

    public function table(Table $table): Table
    {
        return $table
            ->query(ShippingSetting::query()->with('fulfillmentMailTemplate'))
            ->heading('Shipping providers')
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\IconColumn::make('is_default')
                    ->label('Default')
                    ->boolean()
                    ->sortable(),

                Tables\Columns\TextColumn::make('items_on_euroaluse')
                    ->label('Items on euroaluse')
                    ->sortable(),

                Tables\Columns\TextColumn::make('euroaluse_price')
                    ->label('Euroaluse price')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_buffer')
                    ->label('Default buffer')
                    ->sortable(),

                Tables\Columns\TextColumn::make('fulfillment_warehouse_email')
                    ->label('Warehouse email')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('fulfillmentMailTemplate.name')
                    ->label('Mail template')
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->model(ShippingSetting::class)
                    ->label('Add provider')
                    ->modalHeading('Add shipping provider')
                    ->schema(self::providerFormSchema())
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['items_on_euroaluse'] = (int) ($data['items_on_euroaluse'] ?? 1);
                        $data['default_buffer'] = (int) ($data['default_buffer'] ?? 0);
                        $data['fulfillment_warehouse_email'] = filled($data['fulfillment_warehouse_email'] ?? null)
                            ? $data['fulfillment_warehouse_email']
                            : null;
                        $data['fulfillment_mail_template_id'] = filled($data['fulfillment_mail_template_id'] ?? null)
                            ? (int) $data['fulfillment_mail_template_id']
                            : null;

                        if (! ShippingSetting::query()->exists()) {
                            $data['is_default'] = true;
                        }

                        return $data;
                    })
                    ->successNotificationTitle('Shipping provider created'),
            ])
            ->recordActions([
                EditAction::make()
                    ->modalHeading(fn (ShippingSetting $record): string => 'Edit: '.$record->name)
                    ->schema(self::providerFormSchema())
                    ->mutateFormDataUsing(function (array $data): array {
                        $data['items_on_euroaluse'] = (int) ($data['items_on_euroaluse'] ?? 1);
                        $data['default_buffer'] = (int) ($data['default_buffer'] ?? 0);
                        $data['fulfillment_warehouse_email'] = filled($data['fulfillment_warehouse_email'] ?? null)
                            ? $data['fulfillment_warehouse_email']
                            : null;
                        $data['fulfillment_mail_template_id'] = filled($data['fulfillment_mail_template_id'] ?? null)
                            ? (int) $data['fulfillment_mail_template_id']
                            : null;

                        return $data;
                    })
                    ->successNotificationTitle('Shipping provider updated'),
                DeleteAction::make()
                    ->visible(fn (): bool => ShippingSetting::query()->count() > 1)
                    ->successNotificationTitle('Shipping provider deleted'),
            ])
            ->defaultSort('name');
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component>
     */
    public static function providerFormSchema(): array
    {
        return [
            TextInput::make('name')
                ->label('Provider name')
                ->required()
                ->maxLength(255),

            Toggle::make('is_default')
                ->label('Default provider')
                ->helperText('Used for fulfillment emails when no other provider is selected.')
                ->default(false),

            TextInput::make('items_on_euroaluse')
                ->label('Items on euroaluse')
                ->numeric()
                ->integer()
                ->minValue(1)
                ->default(1)
                ->required(),

            TextInput::make('euroaluse_price')
                ->label('Euroaluse price')
                ->numeric()
                ->minValue(0)
                ->step(0.01)
                ->default(0)
                ->required(),

            TextInput::make('default_buffer')
                ->label('Default buffer')
                ->numeric()
                ->integer()
                ->minValue(0)
                ->default(0)
                ->required(),

            TextInput::make('fulfillment_warehouse_email')
                ->label('Fulfillment warehouse email')
                ->email()
                ->maxLength(255),

            Select::make('fulfillment_mail_template_id')
                ->label('Fulfillment mail template')
                ->options(fn (): array => MailTemplate::query()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->native(false)
                ->helperText('Optional. Used for subject, from name, and email body when an order is approved.'),
        ];
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->hasRole('admin') ?? false;
    }
}
