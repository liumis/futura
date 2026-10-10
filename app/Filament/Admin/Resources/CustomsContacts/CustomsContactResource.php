<?php

namespace App\Filament\Admin\Resources\CustomsContacts;

use App\Models\CustomsContact;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use UnitEnum;

class CustomsContactResource extends Resource
{
    protected static ?string $model = CustomsContact::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-building-office';

    protected static ?string $navigationLabel = 'Customs contacts';

    protected static ?string $modelLabel = 'Customs contact';

    protected static ?string $pluralModelLabel = 'Customs contacts';

    protected static string|UnitEnum|null $navigationGroup = 'Financial options';

    protected static ?int $navigationSort = 7;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('company_name')
                    ->label('Company name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('company_code')
                    ->label('Code')
                    ->maxLength(255),

                Forms\Components\TextInput::make('vat_code')
                    ->label('VAT code')
                    ->maxLength(255),

                Forms\Components\Textarea::make('address')
                    ->label('Address')
                    ->rows(3)
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(50),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),

                Forms\Components\Select::make('mail_template_id')
                    ->label('Email template')
                    ->relationship('mailTemplate', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->helperText('Used when sending customs documents from a confirmed warehouse order.'),

                Forms\Components\TextInput::make('bank_account')
                    ->label('Bank account (IBAN)')
                    ->helperText('Used for SEB payment files from Import taxes.')
                    ->maxLength(34)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),

                Forms\Components\TextInput::make('bank_bic')
                    ->label('BIC')
                    ->helperText('Optional. SEB Lithuania is often CBVILT2X.')
                    ->maxLength(11)
                    ->dehydrateStateUsing(fn (?string $state): ?string => filled($state)
                        ? strtoupper(preg_replace('/\s+/', '', $state) ?? '')
                        : null),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('company_name')
                    ->label('Company name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('company_code')
                    ->label('Code')
                    ->searchable()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vat_code')
                    ->label('VAT code')
                    ->searchable()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('phone')
                    ->label('Phone')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('mailTemplate.name')
                    ->label('Email template')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('bank_account')
                    ->label('IBAN')
                    ->placeholder('—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('company_name')
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
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
            'index' => Pages\ListCustomsContacts::route('/'),
            'create' => Pages\CreateCustomsContact::route('/create'),
            'edit' => Pages\EditCustomsContact::route('/{record}/edit'),
        ];
    }
}
