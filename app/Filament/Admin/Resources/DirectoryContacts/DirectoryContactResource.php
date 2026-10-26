<?php

namespace App\Filament\Admin\Resources\DirectoryContacts;

use App\Enums\DirectoryContactPriority;
use App\Filament\Admin\Support\DoubleClickCellEdit;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactStage;
use App\Models\OriginCountry;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use UnitEnum;

class DirectoryContactResource extends Resource
{
    protected static ?string $model = DirectoryContact::class;

    protected static ?string $slug = 'directory-contacts';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationLabel = 'Contacts';

    protected static ?string $modelLabel = 'Contact';

    protected static ?string $pluralModelLabel = 'Contacts';

    protected static string|UnitEnum|null $navigationGroup = 'Contacts';

    protected static ?int $navigationSort = 1;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\TextInput::make('company_name')
                    ->label('Company name')
                    ->required()
                    ->maxLength(255),

                Forms\Components\TextInput::make('type')
                    ->label('Type')
                    ->placeholder('e.g. Manufacturer')
                    ->datalist([
                        'Manufacturer',
                        'Retailer',
                        'Distributor',
                        'Contract',
                        'Other',
                    ])
                    ->maxLength(255),

                Forms\Components\Select::make('country')
                    ->label('Country')
                    ->options(OriginCountry::options())
                    ->searchable()
                    ->preload()
                    ->native(false),

                Forms\Components\TextInput::make('segment')
                    ->label('Segment')
                    ->placeholder('e.g. Sofas / leather')
                    ->datalist([
                        'Sofas',
                        'Leather sofas',
                        'Premium upholstered',
                        'Contract / office seating',
                        'Upholstered',
                        'Leather furniture',
                    ])
                    ->maxLength(255),

                Forms\Components\Select::make('priority')
                    ->label('Priority')
                    ->options(DirectoryContactPriority::options())
                    ->native(false),

                Forms\Components\TextInput::make('website')
                    ->label('Website')
                    ->prefix('https://')
                    ->placeholder('example.com')
                    ->maxLength(255)
                    ->dehydrateStateUsing(function (?string $state): ?string {
                        $state = trim((string) $state);
                        if ($state === '') {
                            return null;
                        }

                        $state = preg_replace('#^https?://#i', '', $state) ?? $state;

                        return strtolower(rtrim($state, '/'));
                    }),

                Forms\Components\TextInput::make('email')
                    ->label('Email')
                    ->email()
                    ->maxLength(255),

                Forms\Components\TextInput::make('phone')
                    ->label('Phone')
                    ->tel()
                    ->maxLength(255),

                Forms\Components\Select::make('directory_contact_stage_id')
                    ->label('Stage')
                    ->relationship(
                        'stage',
                        'name',
                        fn (Builder $query) => $query->orderBy('sort_order')->orderBy('id'),
                    )
                    ->searchable()
                    ->preload()
                    ->native(false)
                    ->default(fn (): int => DirectoryContactStage::defaultId())
                    ->required(),

                Forms\Components\DatePicker::make('last_contact')
                    ->label('Last contact')
                    ->native(false),

                Forms\Components\DatePicker::make('next_contact')
                    ->label('Next contact')
                    ->native(false),

                Section::make('Comment (for myself)')
                    ->description('Internal notes. Each note is stored with a date.')
                    ->schema([
                        Forms\Components\Repeater::make('comments')
                            ->relationship(
                                'comments',
                                modifyQueryUsing: fn (Builder $query) => $query
                                    ->orderByDesc('commented_at')
                                    ->orderByDesc('id'),
                            )
                            ->label('')
                            ->schema([
                                Forms\Components\DateTimePicker::make('commented_at')
                                    ->label('Date')
                                    ->required()
                                    ->default(fn (): string => now()->format('Y-m-d H:i:s'))
                                    ->seconds(false)
                                    ->native(false),

                                Forms\Components\Textarea::make('comment')
                                    ->label('Comment')
                                    ->required()
                                    ->rows(3)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->defaultItems(0)
                            ->addActionLabel('Add comment')
                            ->mutateRelationshipDataBeforeCreateUsing(function (array $data): array {
                                $data['user_id'] = auth()->id();

                                return $data;
                            })
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Contact persons')
                    ->schema([
                        Forms\Components\Repeater::make('contactPersons')
                            ->relationship()
                            ->label('')
                            ->schema([
                                Forms\Components\TextInput::make('name')
                                    ->label('Name')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('surname')
                                    ->label('Surname')
                                    ->required()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('phone')
                                    ->label('Phone')
                                    ->tel()
                                    ->maxLength(255),

                                Forms\Components\TextInput::make('email')
                                    ->label('Email')
                                    ->email()
                                    ->maxLength(255),

                                Forms\Components\Textarea::make('comment')
                                    ->label('Comment')
                                    ->rows(2)
                                    ->columnSpanFull(),
                            ])
                            ->columns(2)
                            ->addActionLabel('Add contact person')
                            ->defaultItems(0)
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('company_name')
                        ->label('Company name')
                        ->searchable()
                        ->sortable(),
                    'company_name',
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('type')
                        ->label('Type')
                        ->searchable()
                        ->sortable()
                        ->placeholder('—')
                        ->toggleable(),
                    'type',
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('segment')
                        ->label('Segment')
                        ->searchable()
                        ->sortable()
                        ->placeholder('—')
                        ->toggleable(),
                    'segment',
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('priority')
                        ->label('Priority')
                        ->badge()
                        ->formatStateUsing(fn (?DirectoryContactPriority $state): string => $state?->label() ?? '—')
                        ->color(fn (?DirectoryContactPriority $state): string => match ($state) {
                            DirectoryContactPriority::High => 'danger',
                            DirectoryContactPriority::Medium => 'warning',
                            DirectoryContactPriority::Low => 'gray',
                            default => 'gray',
                        })
                        ->sortable()
                        ->toggleable(),
                    'priority',
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('website')
                        ->label('Website')
                        ->url(fn (DirectoryContact $record): ?string => filled($record->website)
                            ? 'https://'.$record->website
                            : null)
                        ->openUrlInNewTab()
                        ->placeholder('—')
                        ->toggleable(),
                    'website',
                ),

                DoubleClickCellEdit::enable(
                    Tables\Columns\TextColumn::make('stage.name')
                        ->label('Stage')
                        ->placeholder('—')
                        ->sortable(),
                    'stage',
                ),

                Tables\Columns\TextColumn::make('last_contact')
                    ->label('Last contact')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('next_contact')
                    ->label('Next contact')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('country')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('email')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('phone')
                    ->searchable()
                    ->placeholder('—'),

                Tables\Columns\TextColumn::make('persons_list')
                    ->label('Contact persons')
                    ->getStateUsing(fn (DirectoryContact $record): string => $record->contactPersons
                        ->map(fn ($person): string => $person->fullName())
                        ->filter()
                        ->implode(', '))
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('comments_count')
                    ->label('Comments')
                    ->counts('comments')
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->iconColor('gray')
                    ->sortable(),

                Tables\Columns\TextColumn::make('latestComment.comment')
                    ->label('Last comment')
                    ->state(fn (DirectoryContact $record): ?string => $record->latestComment?->comment)
                    ->formatStateUsing(fn (?string $state): ?string => filled($state)
                        ? Str::limit($state, 60, '...')
                        : null)
                    ->tooltip(function (DirectoryContact $record): ?string {
                        $comment = $record->latestComment;

                        if ($comment === null || blank($comment->comment)) {
                            return null;
                        }

                        $date = (string) ($comment->commented_at?->format('Y-m-d H:i') ?? '');
                        $body = (string) $comment->comment;

                        return trim($date."\n".$body);
                    })
                    ->placeholder('—')
                    ->wrap(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('company_name')
            ->filters([
                Tables\Filters\SelectFilter::make('directory_contact_stage_id')
                    ->label('Stage')
                    ->relationship('stage', 'name')
                    ->searchable()
                    ->preload()
                    ->native(false),

                Tables\Filters\SelectFilter::make('priority')
                    ->label('Priority')
                    ->options(DirectoryContactPriority::options())
                    ->native(false),

                Tables\Filters\SelectFilter::make('type')
                    ->label('Type')
                    ->options(function (): array {
                        return DirectoryContact::query()
                            ->selectRaw('type, COUNT(*) as contacts_count')
                            ->whereNotNull('type')
                            ->where('type', '!=', '')
                            ->groupBy('type')
                            ->orderBy('type')
                            ->get()
                            ->mapWithKeys(fn ($row): array => [
                                (string) $row->type => $row->type.' ('.$row->contacts_count.')',
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false),

                Tables\Filters\SelectFilter::make('country')
                    ->label('Country')
                    ->options(function (): array {
                        return DirectoryContact::query()
                            ->selectRaw('country, COUNT(*) as contacts_count')
                            ->whereNotNull('country')
                            ->where('country', '!=', '')
                            ->groupBy('country')
                            ->orderBy('country')
                            ->get()
                            ->mapWithKeys(fn ($row): array => [
                                (string) $row->country => $row->country.' ('.$row->contacts_count.')',
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->native(false),

                Tables\Filters\SelectFilter::make('company_name')
                    ->label('Company name')
                    ->options(fn (): array => DirectoryContact::query()
                        ->orderBy('company_name')
                        ->pluck('company_name', 'company_name')
                        ->all())
                    ->searchable()
                    ->preload()
                    ->native(false),

                Filter::make('last_contact_range')
                    ->label('Last contact')
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label('From')
                            ->native(false),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        if (filled($data['from'] ?? null)) {
                            $query->whereDate('last_contact', '>=', $data['from']);
                        }

                        if (filled($data['until'] ?? null)) {
                            $query->whereDate('last_contact', '<=', $data['until']);
                        }
                    }),

                Filter::make('next_contact_range')
                    ->label('Next contact')
                    ->schema([
                        Forms\Components\DatePicker::make('from')
                            ->label('From')
                            ->native(false),
                        Forms\Components\DatePicker::make('until')
                            ->label('Until')
                            ->native(false),
                    ])
                    ->query(function (Builder $query, array $data): void {
                        if (filled($data['from'] ?? null)) {
                            $query->whereDate('next_contact', '>=', $data['from']);
                        }

                        if (filled($data['until'] ?? null)) {
                            $query->whereDate('next_contact', '<=', $data['until']);
                        }
                    }),
            ])
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
        return parent::getEloquentQuery()->with(['contactPersons', 'latestComment', 'stage']);
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
            'index' => Pages\ListDirectoryContacts::route('/'),
            'create' => Pages\CreateDirectoryContact::route('/create'),
            'edit' => Pages\EditDirectoryContact::route('/{record}/edit'),
        ];
    }
}
