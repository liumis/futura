<?php



namespace App\Filament\Admin\Resources\StockManualUpdates;



use App\Filament\Admin\Resources\StockManualUpdates\Pages\ListStockManualUpdates;

use App\Models\StockManualUpdate;

use App\Models\User;

use App\Services\WriteOffDocumentCreator;

use BackedEnum;

use Filament\Actions\BulkAction;

use Filament\Forms\Components\DatePicker;

use Filament\Notifications\Notification;

use Filament\Resources\Resource;

use Filament\Schemas\Schema;

use Filament\Tables;

use Filament\Tables\Filters\Filter;

use Filament\Tables\Filters\SelectFilter;

use Filament\Tables\Table;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use UnitEnum;



class StockManualUpdateResource extends Resource

{

    protected static ?string $model = StockManualUpdate::class;



    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-clipboard-document-check';



    protected static ?string $navigationLabel = 'Manual write-off';



    protected static ?string $modelLabel = 'manual write-off';



    protected static ?string $pluralModelLabel = 'Manual write-off';



    protected static string|UnitEnum|null $navigationGroup = 'Warehouse & shipping';



    protected static ?int $navigationSort = 4;



    public static function form(Schema $schema): Schema

    {

        return $schema->components([]);

    }



    public static function table(Table $table): Table

    {

        return $table

            ->columns([

                Tables\Columns\IconColumn::make('documented')

                    ->label('In document')

                    ->boolean()

                    ->getStateUsing(fn (StockManualUpdate $record): bool => filled($record->write_off_document_id))

                    ->trueIcon('heroicon-o-document-check')

                    ->falseIcon('heroicon-o-minus')

                    ->trueColor('success')

                    ->falseColor('gray')

                    ->tooltip(fn (StockManualUpdate $record): ?string => filled($record->write_off_document_id)

                        ? 'Open write-off document'

                        : 'Not yet included in a document')

                    ->url(function (bool $state, StockManualUpdate $record): ?string {
                        if (! $state || blank($record->write_off_document_id) || ! $record->writeOffDocument) {
                            return null;
                        }

                        return route('write-off-documents.file', [
                            'writeOffDocument' => $record->writeOffDocument,
                            'lang' => 'en',
                        ]);
                    })

                    ->openUrlInNewTab(),



                Tables\Columns\TextColumn::make('created_at')

                    ->label('When')

                    ->dateTime()

                    ->sortable(),



                Tables\Columns\TextColumn::make('user.name')

                    ->label('User')

                    ->searchable()

                    ->sortable()

                    ->placeholder('—'),



                Tables\Columns\TextColumn::make('product.color.collection.name')

                    ->label('Collection')

                    ->sortable()

                    ->placeholder('—'),



                Tables\Columns\TextColumn::make('product.color.color_name')

                    ->label('Color')

                    ->sortable()

                    ->placeholder('—'),



                Tables\Columns\TextColumn::make('product.product_code')

                    ->label('Product code')

                    ->searchable()

                    ->sortable()

                    ->placeholder('—'),



                Tables\Columns\TextColumn::make('product.name')

                    ->label('Size (m)')

                    ->sortable()

                    ->placeholder('—'),



                Tables\Columns\TextColumn::make('old_amount')

                    ->label('Old stock')

                    ->numeric()

                    ->alignEnd()

                    ->sortable(),



                Tables\Columns\TextColumn::make('new_amount')

                    ->label('New stock')

                    ->numeric()

                    ->alignEnd()

                    ->sortable(),



                Tables\Columns\TextColumn::make('delta')

                    ->label('Change')

                    ->getStateUsing(fn (StockManualUpdate $record): string => sprintf(

                        '%+d',

                        $record->delta(),

                    ))

                    ->alignEnd()

                    ->color(fn (StockManualUpdate $record): string => match (true) {

                        $record->delta() > 0 => 'success',

                        $record->delta() < 0 => 'danger',

                        default => 'gray',

                    }),



                Tables\Columns\TextColumn::make('writeOffDocument.document_date')

                    ->label('Document date')

                    ->date()

                    ->placeholder('—')

                    ->sortable(),



                Tables\Columns\TextColumn::make('document_links')
                    ->label('Document')
                    ->placeholder('—')
                    ->formatStateUsing(function (StockManualUpdate $record): ?HtmlString {
                        if (blank($record->write_off_document_id) || ! $record->writeOffDocument) {
                            return null;
                        }

                        $document = $record->writeOffDocument;
                        $ltUrl = e(route('write-off-documents.file', ['writeOffDocument' => $document, 'lang' => 'lt']));
                        $enUrl = e(route('write-off-documents.file', ['writeOffDocument' => $document, 'lang' => 'en']));

                        return new HtmlString(
                            e($document->document_number).' '
                            .'<span class="whitespace-nowrap">'
                            .'(<a href="'.$ltUrl.'" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">LT</a>'
                            .' / '
                            .'<a href="'.$enUrl.'" target="_blank" class="text-primary-600 hover:underline dark:text-primary-400">EN</a>)'
                            .'</span>'
                        );
                    })
                    ->html(),

            ])

            ->filters([

                Filter::make('created_at')

                    ->label('Date')

                    ->schema([

                        DatePicker::make('from')

                            ->label('From'),

                        DatePicker::make('until')

                            ->label('Until'),

                    ])

                    ->query(function (Builder $query, array $data): void {

                        $from = $data['from'] ?? null;

                        $until = $data['until'] ?? null;



                        if (blank($from) && blank($until)) {

                            return;

                        }



                        $column = $query->getModel()->qualifyColumn('created_at');



                        if (filled($from)) {

                            $query->whereDate($column, '>=', $from);

                        }



                        if (filled($until)) {

                            $query->whereDate($column, '<=', $until);

                        }

                    }),



                SelectFilter::make('user_id')

                    ->label('User')

                    ->relationship('user', 'name', fn (Builder $query) => $query->orderBy('name'))

                    ->searchable()

                    ->preload()

                    ->native(false)

                    ->getOptionLabelFromRecordUsing(fn (User $record): string => (string) ($record->name ?? $record->email ?? '')),



                Tables\Filters\TernaryFilter::make('documented')

                    ->label('Document status')

                    ->placeholder('All rows')

                    ->trueLabel('In document only')

                    ->falseLabel('Not in document')

                    ->queries(

                        true: fn (Builder $query): Builder => $query->whereNotNull('write_off_document_id'),

                        false: fn (Builder $query): Builder => $query->whereNull('write_off_document_id'),

                    ),

            ])

            ->selectable()

            ->checkIfRecordIsSelectableUsing(

                fn (StockManualUpdate $record): bool => blank($record->write_off_document_id),

            )

            ->defaultSort('created_at', 'desc')

            ->recordActions([])

            ->toolbarActions([

                BulkAction::make('create_document')

                    ->label('Create document')

                    ->icon('heroicon-o-document-plus')

                    ->modalHeading('Create write-off document')

                    ->modalSubmitActionLabel('Create document')

                    ->form([

                        DatePicker::make('document_date')

                            ->label('Document date')

                            ->required()

                            ->default(now())

                            ->native(false),

                    ])

                    ->action(function (Collection $records, array $data): void {

                        if ($records->isEmpty()) {

                            Notification::make()

                                ->title('Select at least one row')

                                ->warning()

                                ->send();



                            return;

                        }



                        try {

                            $document = WriteOffDocumentCreator::create(

                                $records,

                                Carbon::parse((string) $data['document_date'])->startOfDay(),

                            );

                        } catch (\InvalidArgumentException $exception) {

                            Notification::make()

                                ->title($exception->getMessage())

                                ->danger()

                                ->send();



                            return;

                        }



                        Notification::make()

                            ->title('Write-off document created')

                            ->body($document->document_number)

                            ->success()

                            ->actions([

                                \Filament\Actions\Action::make('open')

                                    ->label('Open PDF')

                                    ->url(route('write-off-documents.file', $document), shouldOpenInNewTab: true),

                            ])

                            ->send();

                    })

                    ->deselectRecordsAfterCompletion(),

            ]);

    }



    public static function getPages(): array

    {

        return [

            'index' => ListStockManualUpdates::route('/'),

        ];

    }



    public static function getEloquentQuery(): Builder

    {

        return parent::getEloquentQuery()->with(['product.color.collection', 'user', 'writeOffDocument']);

    }



    public static function canViewAny(): bool

    {

        return auth()->user()?->hasRole('admin') ?? false;

    }



    public static function canCreate(): bool

    {

        return false;

    }



    public static function canEdit($record): bool

    {

        return false;

    }



    public static function canDelete($record): bool

    {

        return false;

    }

}


