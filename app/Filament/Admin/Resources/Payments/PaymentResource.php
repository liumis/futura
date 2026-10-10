<?php

namespace App\Filament\Admin\Resources\Payments;

use App\Enums\PaymentFrequency;
use App\Enums\PaymentSchedule;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Models\Contact;
use App\Models\Payment;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class PaymentResource extends Resource
{
    protected static ?string $model = Payment::class;

    protected static ?string $slug = 'payments';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Payments';

    protected static ?string $modelLabel = 'Payment';

    protected static ?string $pluralModelLabel = 'Payments';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('name')
                    ->label('Name')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                Forms\Components\Select::make('contact_id')
                    ->label('Company')
                    ->options(fn (): array => Contact::query()->orderBy('company_name')->pluck('company_name', 'id')->all())
                    ->searchable()
                    ->preload()
                    ->native(false),

                Forms\Components\TextInput::make('amount')
                    ->label('Amount')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix()),

                Forms\Components\Select::make('schedule')
                    ->label('Payment type')
                    ->options(PaymentSchedule::options())
                    ->default(PaymentSchedule::OneTime->value)
                    ->required()
                    ->native(false)
                    ->live(),

                Forms\Components\Select::make('frequency')
                    ->label('Frequency')
                    ->options(PaymentFrequency::options())
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value)
                    ->required(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value)
                    ->dehydrated(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value),

                Forms\Components\DatePicker::make('due_date')
                    ->label('Due date')
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('schedule') !== PaymentSchedule::Periodical->value)
                    ->required(fn (Get $get): bool => $get('schedule') !== PaymentSchedule::Periodical->value)
                    ->dehydrated(fn (Get $get): bool => $get('schedule') !== PaymentSchedule::Periodical->value),

                Forms\Components\DatePicker::make('start_date')
                    ->label('Start date')
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value)
                    ->required(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value)
                    ->dehydrated(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value),

                Forms\Components\DatePicker::make('end_date')
                    ->label('End date')
                    ->native(false)
                    ->visible(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value)
                    ->dehydrated(fn (Get $get): bool => $get('schedule') === PaymentSchedule::Periodical->value),

                Forms\Components\Checkbox::make('paid')
                    ->label('Paid')
                    ->inline(false)
                    ->visible(fn (Get $get): bool => $get('schedule') !== PaymentSchedule::Periodical->value)
                    ->dehydrated(fn (Get $get): bool => $get('schedule') !== PaymentSchedule::Periodical->value),

                Forms\Components\FileUpload::make('documents')
                    ->label('Agreement / contract')
                    ->multiple()
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'image/webp',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                    ])
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->disk('public')
                    ->directory('payments')
                    ->helperText(UploadLimits::withExistingNote('PDF, Word, or image of the agreement or contract.'))
                    ->downloadable()
                    ->openable()
                    ->columnSpanFull(),

                Forms\Components\Textarea::make('notes')
                    ->label('Notes')
                    ->rows(3)
                    ->columnSpanFull(),

                Forms\Components\Hidden::make('uploaded_by')
                    ->default(fn (): ?int => auth()->id()),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                Tables\Columns\TextColumn::make('contact.company_name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('schedule')
                    ->label('Type')
                    ->badge()
                    ->sortable(),

                Tables\Columns\TextColumn::make('frequency')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('next_due')
                    ->label('Next / due')
                    ->getStateUsing(fn (Payment $record): ?string => $record->nextDueDate()?->toDateString())
                    ->placeholder('—')
                    ->sortable(query: function (Builder $query, string $direction): Builder {
                        return $query->orderByRaw(
                            'COALESCE(due_date, start_date) '.$direction
                        );
                    }),

                Tables\Columns\IconColumn::make('paid')
                    ->boolean()
                    ->getStateUsing(fn (Payment $record): ?bool => $record->isOneTime() ? (bool) $record->paid : null)
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('documents')
                    ->label('Document')
                    ->getStateUsing(fn (Payment $record): string => $record->documentCount() > 0
                        ? $record->documentCount().' file(s)'
                        : '—')
                    ->url(fn (Payment $record): ?string => $record->firstDocumentUrl())
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('id', 'desc')
            ->filters([
                SelectFilter::make('schedule')
                    ->label('Type')
                    ->options(PaymentSchedule::options()),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->emptyStateHeading('No payments yet')
            ->emptyStateDescription('Add a payment and upload the agreement or contract.');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['contact', 'uploadedUser']);
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
            'index' => ListPayments::route('/'),
            'create' => CreatePayment::route('/create'),
            'edit' => EditPayment::route('/{record}/edit'),
        ];
    }
}
