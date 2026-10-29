<?php

namespace App\Filament\Admin\Resources\Users;

use App\Enums\AdminModule;
use App\Enums\InvoiceLanguage;
use App\Enums\NotificationType;
use App\Models\Invoice;
use App\Models\OriginCountry;
use App\Models\User;
use App\Models\VatRate;
use App\Services\VatRateResolver;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $modelLabel = 'User';

    protected static ?string $pluralModelLabel = 'Users';

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('First name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('surname')
                    ->label('Surname')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('email')
                    ->email()
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),

                Forms\Components\TextInput::make('phone')
                    ->tel()
                    ->maxLength(50)
                    ->label('Phone'),

                Forms\Components\TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->required(fn (string $operation): bool => $operation === 'create')
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->maxLength(255)
                    ->label('Password')
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Leave blank to keep the current password.'
                        : null),

                Forms\Components\Select::make('roles')
                    ->relationship(
                        'roles',
                        'name',
                        function (Builder $query): Builder {
                            $names = auth()->user()?->assignableRoleNames() ?? [];

                            return $query
                                ->where('guard_name', 'web')
                                ->when(
                                    $names === [],
                                    fn (Builder $q): Builder => $q->whereRaw('0 = 1'),
                                    fn (Builder $q): Builder => $q->whereIn('name', $names),
                                );
                        },
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->required()
                    ->live()
                    ->label('Roles'),

                Forms\Components\Select::make('modules')
                    ->label('Additional modules')
                    ->helperText('Project managers always have Tasks. Select any other modules they may access.')
                    ->options(AdminModule::additionalOptionsForProjectManager())
                    ->multiple()
                    ->native(false)
                    ->searchable()
                    ->visible(fn (callable $get): bool => User::formStateIncludesProjectManagerRole(
                        is_array($get('roles')) ? $get('roles') : null
                    ))
                    ->dehydrated(fn (callable $get): bool => User::formStateIncludesProjectManagerRole(
                        is_array($get('roles')) ? $get('roles') : null
                    )),

                Forms\Components\Select::make('homepage_module')
                    ->label('Homepage')
                    ->helperText('Opened after login and when the logo is clicked.')
                    ->options(AdminModule::options())
                    ->native(false)
                    ->searchable()
                    ->placeholder('Dashboard')
                    ->visible(fn (callable $get): bool => filled($get('roles')) && ! User::formStateIncludesCustomerRole($get('roles')))
                    ->dehydrated(fn (callable $get): bool => filled($get('roles')) && ! User::formStateIncludesCustomerRole($get('roles'))),

                Forms\Components\Select::make('notification_types')
                    ->label('Notifications')
                    ->helperText('Notification types this user should receive.')
                    ->options(NotificationType::options())
                    ->multiple()
                    ->native(false)
                    ->visible(fn (callable $get): bool => filled($get('roles')) && ! User::formStateIncludesCustomerRole($get('roles')))
                    ->dehydrated(fn (callable $get): bool => filled($get('roles')) && ! User::formStateIncludesCustomerRole($get('roles'))),

                Forms\Components\Select::make('customer_level_id')
                    ->relationship('customerLevel', 'name')
                    ->searchable()
                    ->preload()
                    ->label('Customer level')
                    ->placeholder('Select level')
                    ->visible(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles')))
                    ->dehydrated(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles'))),

                Forms\Components\Select::make('vat_rate_id')
                    ->label('PVM/VAT classificator')
                    ->options(fn (): array => VatRate::query()
                        ->orderBy('classificator')
                        ->get()
                        ->mapWithKeys(fn (VatRate $rate): array => [
                            $rate->id => VatRateResolver::optionLabel($rate),
                        ])
                        ->all())
                    ->default(fn (): ?int => VatRate::query()->where('classificator', 'PVM1')->value('id')
                        ?? VatRate::query()->orderBy('id')->value('id'))
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->visible(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles')))
                    ->dehydrated(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles'))),

                Forms\Components\Select::make('invoice_language')
                    ->label('Invoice language')
                    ->options(InvoiceLanguage::options())
                    ->default(InvoiceLanguage::English->value)
                    ->native(false)
                    ->visible(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles')))
                    ->dehydrated(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles'))),

                Forms\Components\TextInput::make('payment_term')
                    ->label('Payment term')
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->default(Invoice::DEFAULT_PAYMENT_TERM_DAYS)
                    ->suffix('days')
                    ->helperText('Number of days until payment is due. Default is '.Invoice::DEFAULT_PAYMENT_TERM_DAYS.' days.')
                    ->visible(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles')))
                    ->dehydrated(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles'))),

                Section::make('Company')
                    ->description('Optional company details (customers only)')
                    ->visible(fn (callable $get): bool => User::formStateIncludesCustomerRole($get('roles')))
                    ->schema([
                        Forms\Components\TextInput::make('company_name')
                            ->maxLength(255)
                            ->label('Company name')
                            ->columnSpanFull(),

                        Forms\Components\Select::make('company_country')
                            ->label('Company country')
                            ->options(OriginCountry::options())
                            ->searchable()
                            ->preload()
                            ->live()
                            ->afterStateUpdated(function (?string $state, callable $set): void {
                                $country = strtolower(trim((string) $state));
                                $isLt = in_array($country, ['lithuania', 'lietuva', 'lt'], true);
                                $set('export', ! $isLt && filled($state));
                                $set(
                                    'vat_rate_id',
                                    VatRate::query()
                                        ->where('classificator', $isLt || blank($state) ? 'PVM1' : 'PVM4')
                                        ->value('id'),
                                );
                            })
                            ->columnSpanFull(),

                        Forms\Components\Checkbox::make('export')
                            ->label('Export')
                            ->inline(false)
                            ->live()
                            ->afterStateUpdated(function (?bool $state, callable $set): void {
                                $set(
                                    'vat_rate_id',
                                    VatRate::query()
                                        ->where('classificator', $state ? 'PVM4' : 'PVM1')
                                        ->value('id'),
                                );
                            })
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('company_address')
                            ->rows(3)
                            ->maxLength(2000)
                            ->label('Company address')
                            ->columnSpanFull(),

                        Forms\Components\Textarea::make('company_shipping_address')
                            ->rows(3)
                            ->maxLength(2000)
                            ->label('Company shipping address')
                            ->columnSpanFull(),

                        Forms\Components\TextInput::make('company_code')
                            ->maxLength(100)
                            ->label('Company code'),

                        Forms\Components\TextInput::make('company_vat')
                            ->maxLength(100)
                            ->label('Company VAT'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->label('Name')
                    ->formatStateUsing(fn ($state, User $record): string => $record->fullName() !== '' ? $record->fullName() : '—')
                    ->searchable(query: function (Builder $query, string $search): Builder {
                        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $search).'%';

                        return $query->where(function (Builder $inner) use ($term): void {
                            $inner
                                ->where('name', 'like', $term)
                                ->orWhere('surname', 'like', $term)
                                ->orWhereRaw(
                                    "TRIM(CONCAT(COALESCE(name, ''), ' ', COALESCE(surname, ''))) like ?",
                                    [$term],
                                );
                        });
                    })
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query
                            ->orderBy('name', $direction)
                            ->orderBy('surname', $direction);
                    }),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('roles.name')
                    ->badge(),

                Tables\Columns\TextColumn::make('customerLevel.name')
                    ->label('Customer level')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('vatRate.classificator')
                    ->label('PVM/VAT')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice_language')
                    ->label('Invoice lang.')
                    ->formatStateUsing(fn (?string $state): string => InvoiceLanguage::normalize($state)->label())
                    ->sortable(),

                Tables\Columns\TextColumn::make('payment_term')
                    ->label('Payment term')
                    ->formatStateUsing(fn (mixed $state): string => filled($state) ? ((int) $state).' days' : '—')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label('Role')
                    ->relationship(
                        'roles',
                        'name',
                        fn (Builder $query): Builder => $query
                            ->where('guard_name', 'web')
                            ->orderBy('name'),
                    )
                    ->multiple()
                    ->preload()
                    ->searchable()
                    ->native(false),

                Filter::make('name_surname')
                    ->label('Name')
                    ->schema([
                        Forms\Components\TextInput::make('q')
                            ->label('Name')
                            ->placeholder('Search name or surname…'),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        $q = trim((string) ($data['q'] ?? ''));
                        if ($q === '') {
                            return;
                        }

                        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], $q).'%';

                        $query->where(function (Builder $inner) use ($term): void {
                            $inner
                                ->where('name', 'like', $term)
                                ->orWhere('surname', 'like', $term)
                                ->orWhereRaw(
                                    "TRIM(CONCAT(COALESCE(name, ''), ' ', COALESCE(surname, ''))) like ?",
                                    [$term],
                                );
                        });
                    })
                    ->indicateUsing(function (array $data): array {
                        if (! filled($data['q'] ?? null)) {
                            return [];
                        }

                        return ['Name: '.$data['q']];
                    }),
            ])
            ->persistFiltersInSession()
            ->persistSearchInSession()
            ->recordActions([
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
        return parent::getEloquentQuery()->with(['customerLevel', 'vatRate', 'roles']);
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canCreate(): bool
    {
        return auth()->user()?->canManageUsers() ?? false;
    }

    public static function canEdit($record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $record instanceof User
            ? $actor->canEditUser($record)
            : false;
    }

    public static function canDelete($record): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && $record instanceof User
            ? $actor->canDeleteUser($record)
            : false;
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListUsers::route('/'),
            'create' => Pages\CreateUser::route('/create'),
            'edit' => Pages\EditUser::route('/{record}/edit'),
        ];
    }
}
