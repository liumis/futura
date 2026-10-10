<?php

namespace App\Filament\Admin\Resources\ProjectExpenses;

use App\Filament\Admin\Resources\ProjectExpenses\Pages\CreateProjectExpense;
use App\Filament\Admin\Resources\ProjectExpenses\Pages\EditProjectExpense;
use App\Filament\Admin\Resources\ProjectExpenses\Pages\ListProjectExpenses;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\ProjectExpense;
use App\Services\BankStatements\BankStatementInvoiceMatcher;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class ProjectExpenseResource extends Resource
{
    protected static ?string $model = ProjectExpense::class;

    protected static ?string $slug = 'project-expenses';

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-banknotes';

    protected static ?string $navigationLabel = 'Project expenses';

    protected static ?string $modelLabel = 'Project expense';

    protected static ?string $pluralModelLabel = 'Project expenses';

    protected static string|UnitEnum|null $navigationGroup = 'Tasks';

    protected static ?int $navigationSort = 3;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => Project::query()
                        ->active()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Project $project): array => [
                            $project->getKey() => $project->label(),
                        ])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->required()
                    ->native(false),

                Forms\Components\DatePicker::make('worked_on')
                    ->label('Date')
                    ->native(false)
                    ->default(fn (): string => now()->toDateString()),

                Forms\Components\TextInput::make('hours')
                    ->label('Time (hours)')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.25)
                    ->helperText('Optional — use for time logging.'),

                Forms\Components\TextInput::make('amount')
                    ->label('Expenses')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->helperText('Optional — use for expense amount.'),

                Forms\Components\Select::make('invoice_id')
                    ->label('Invoice')
                    ->helperText('Optional link to an existing invoice.')
                    ->searchable()
                    ->preload(false)
                    ->native(false)
                    ->getSearchResultsUsing(fn (string $search): array => BankStatementInvoiceMatcher::searchInvoices($search))
                    ->getOptionLabelUsing(function ($value): ?string {
                        if (! is_numeric($value)) {
                            return null;
                        }

                        $invoice = Invoice::query()->with('contact')->find((int) $value);

                        return $invoice ? BankStatementInvoiceMatcher::pickerLabel($invoice) : null;
                    })
                    ->nullable(),

                Forms\Components\Placeholder::make('author_display')
                    ->label('Author')
                    ->content(function (?ProjectExpense $record): string {
                        if ($record?->user) {
                            return $record->user->displayName();
                        }

                        return auth()->user()?->displayName() ?? '—';
                    }),

                Forms\Components\Hidden::make('user_id')
                    ->default(fn (): ?int => auth()->id())
                    ->dehydrated(fn (?ProjectExpense $record): bool => $record === null),

                Forms\Components\Textarea::make('comment')
                    ->label('Comment')
                    ->rows(4)
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('existing_expense_photos')
                    ->label('Photos on SharePoint')
                    ->content(function (?ProjectExpense $record): Htmlable {
                        if ($record === null) {
                            return new HtmlString('');
                        }

                        $links = $record->sharepointAttachmentLinks();
                        if ($links === []) {
                            return new HtmlString('<span class="text-sm text-gray-500">No photos on SharePoint yet.</span>');
                        }

                        $items = [];
                        foreach ($links as $file) {
                            $name = e($file['name']);
                            $url = filled($file['url'] ?? null) ? e((string) $file['url']) : null;
                            $items[] = $url
                                ? '<li><a href="'.$url.'" target="_blank" rel="noopener" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a></li>'
                                : '<li>'.$name.'</li>';
                        }

                        return new HtmlString(
                            '<ul class="list-disc space-y-1 ps-5 text-sm text-gray-700 dark:text-gray-200">'.implode('', $items).'</ul>'
                        );
                    })
                    ->visible(fn (?ProjectExpense $record): bool => $record !== null)
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('attachments')
                    ->label(fn (?ProjectExpense $record): string => ($record?->hasSharepointFiles() ?? false)
                        ? 'Add more photos'
                        : 'Photos')
                    ->disk('public')
                    ->directory('project-expenses/tmp')
                    ->visibility('public')
                    ->image()
                    ->multiple()
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->helperText(UploadLimits::withExistingNote(
                        'Stored on SharePoint under project-expenses/{id}-{project}-{date}. Temporary local upload is removed after save.',
                    ))
                    ->downloadable(false)
                    ->openable(false)
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('worked_on')
                    ->label('Date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('project.name')
                    ->label('Project')
                    ->getStateUsing(fn (ProjectExpense $record): string => $record->project?->label() ?? '—')
                    ->searchable(['project.name', 'project.code'])
                    ->sortable(),

                Tables\Columns\TextColumn::make('hours')
                    ->label('Hours')
                    ->numeric(decimalPlaces: 2)
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('amount')
                    ->label('Expenses')
                    ->money(Money::currency())
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('invoice.number')
                    ->label('Invoice')
                    ->getStateUsing(fn (ProjectExpense $record): string => $record->invoice?->displayNumber() ?? '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('comment')
                    ->limit(40)
                    ->wrap()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('user.name')
                    ->label('Author')
                    ->getStateUsing(fn (ProjectExpense $record): string => $record->user?->displayName() ?? '—')
                    ->toggleable(),

                Tables\Columns\TextColumn::make('sharepoint_files')
                    ->label('Photos')
                    ->getStateUsing(fn (ProjectExpense $record): string => $record->hasSharepointFiles()
                        ? (string) count($record->sharepointAttachmentLinks())
                        : '—')
                    ->alignCenter(),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('worked_on', 'desc')
            ->filters([
                SelectFilter::make('project_id')
                    ->label('Project')
                    ->options(fn (): array => Project::query()
                        ->orderBy('name')
                        ->get()
                        ->mapWithKeys(fn (Project $project): array => [
                            $project->getKey() => $project->label(),
                        ])
                        ->all())
                    ->searchable()
                    ->preload()
                    ->native(false),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['project', 'invoice.contact', 'user']);
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
            'index' => ListProjectExpenses::route('/'),
            'create' => CreateProjectExpense::route('/create'),
            'edit' => EditProjectExpense::route('/{record}/edit'),
        ];
    }
}
