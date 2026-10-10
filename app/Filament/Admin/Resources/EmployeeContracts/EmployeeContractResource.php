<?php

namespace App\Filament\Admin\Resources\EmployeeContracts;

use App\Enums\EmployeeContractStatus;
use App\Models\Employee;
use App\Models\EmployeeContract;
use App\Support\Money;
use App\Support\UploadLimits;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\HtmlString;
use UnitEnum;

class EmployeeContractResource extends Resource
{
    protected static ?string $model = EmployeeContract::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-document-text';

    protected static ?string $navigationLabel = 'Employees contracts';

    protected static ?string $modelLabel = 'Employee contract';

    protected static ?string $pluralModelLabel = 'Employees contracts';

    protected static string|UnitEnum|null $navigationGroup = 'Employees & contracts';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Forms\Components\Select::make('employee_id')
                    ->label('Employee')
                    ->relationship(
                        'employee',
                        'name',
                        fn (Builder $query) => $query->orderBy('surname')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Employee $employee): string => $employee->fullName(),
                    )
                    ->searchable(['name', 'surname', 'email'])
                    ->preload()
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, ?EmployeeContract $record) => static::warnIfOverlapping($get, $record)),

                Forms\Components\DatePicker::make('sign_date')
                    ->label('Sign date')
                    ->required()
                    ->native(false),

                Forms\Components\DatePicker::make('effective_date_from')
                    ->label('Effective date from')
                    ->required()
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, ?EmployeeContract $record) => static::warnIfOverlapping($get, $record)),

                Forms\Components\DatePicker::make('valid_to')
                    ->label('Valid to')
                    ->native(false)
                    ->live()
                    ->afterStateUpdated(fn (Get $get, ?EmployeeContract $record) => static::warnIfOverlapping($get, $record)),

                Forms\Components\Placeholder::make('overlap_warning')
                    ->hiddenLabel()
                    ->content(fn (Get $get, ?EmployeeContract $record): HtmlString => new HtmlString(
                        '<p class="text-sm font-medium text-warning-600 dark:text-warning-400">'
                        .e((string) static::overlapWarningFromForm($get, $record))
                        .'</p>',
                    ))
                    ->visible(fn (Get $get, ?EmployeeContract $record): bool => filled(static::overlapWarningFromForm($get, $record)))
                    ->columnSpanFull(),

                Forms\Components\TextInput::make('base_salary')
                    ->label('Base salary')
                    ->numeric()
                    ->required()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix()),

                Forms\Components\TextInput::make('default_bonus')
                    ->label('Default bonus')
                    ->numeric()
                    ->minValue(0)
                    ->step(0.01)
                    ->prefix(Money::prefix())
                    ->placeholder('Optional'),

                Forms\Components\TextInput::make('state_percentage')
                    ->label('State percentage')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(100)
                    ->step(0.01)
                    ->default(100)
                    ->suffix('%'),

                Forms\Components\Select::make('status')
                    ->label('Status')
                    ->options(EmployeeContractStatus::options())
                    ->default(EmployeeContractStatus::Draft->value)
                    ->required()
                    ->native(false)
                    ->live(),

                Forms\Components\Placeholder::make('existing_signed_document')
                    ->label('Signed document')
                    ->content(function (?EmployeeContract $record): HtmlString {
                        $document = $record?->document;
                        if ($document === null) {
                            return new HtmlString('<span class="text-sm text-gray-500">No file attached yet.</span>');
                        }

                        $label = e($document->name ?: 'Employment contract');
                        $url = $document->displayFileUrl();

                        if ($url === null) {
                            return new HtmlString('<span class="text-sm text-gray-700 dark:text-gray-200">'.$label.'</span>');
                        }

                        return new HtmlString(
                            '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="text-sm text-primary-600 hover:underline dark:text-primary-400">'.$label.'</a>'
                        );
                    })
                    ->visible(fn (?EmployeeContract $record): bool => filled($record?->document_id))
                    ->columnSpanFull(),

                Forms\Components\Placeholder::make('existing_termination_document')
                    ->label('Termination document')
                    ->content(function (?EmployeeContract $record): HtmlString {
                        $document = $record?->terminationDocument;
                        $date = $record?->termination_date?->toDateString();
                        $reason = $record?->termination_reason;

                        if ($document === null && blank($date) && blank($reason)) {
                            return new HtmlString('<span class="text-sm text-gray-500">No termination document yet.</span>');
                        }

                        $parts = [];
                        if (filled($date)) {
                            $parts[] = 'Date: '.e((string) $date);
                        }
                        if (filled($reason)) {
                            $parts[] = 'Reason: '.e((string) $reason);
                        }

                        $meta = $parts !== []
                            ? '<div class="text-sm text-gray-700 dark:text-gray-200">'.implode(' · ', $parts).'</div>'
                            : '';

                        if ($document === null) {
                            return new HtmlString($meta);
                        }

                        $label = e($document->name ?: 'Termination document');
                        $fileLinks = $document->attachedFileLinks();
                        $items = [];

                        foreach ($fileLinks as $file) {
                            $name = e((string) ($file['name'] ?? 'file'));
                            $url = filled($file['url'] ?? null) ? e((string) $file['url']) : null;
                            $items[] = $url !== null
                                ? '<li><a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="text-sm text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a></li>'
                                : '<li class="text-sm text-gray-700 dark:text-gray-200">'.$name.'</li>';
                        }

                        $files = $items !== []
                            ? '<ul class="mt-1 list-disc space-y-1 ps-5">'.implode('', $items).'</ul>'
                            : '<div class="mt-1">'.(
                                filled($document->displayFileUrl())
                                    ? '<a href="'.e((string) $document->displayFileUrl()).'" target="_blank" rel="noopener noreferrer" class="text-sm text-primary-600 hover:underline dark:text-primary-400">'.$label.'</a>'
                                    : '<span class="text-sm text-gray-700 dark:text-gray-200">'.$label.'</span>'
                            ).'</div>';

                        return new HtmlString($meta.$files);
                    })
                    ->visible(fn (?EmployeeContract $record): bool => filled($record?->termination_document_id) || filled($record?->termination_date))
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('additional_termination_files')
                    ->label('Add termination files')
                    ->helperText(UploadLimits::withExistingNote(
                        'Optional. Attach extra signed scans or supporting files. Existing termination files are kept. PDF, Word (.doc, .docx), JPEG, or PNG.',
                    ))
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        '.doc',
                        '.docx',
                    ])
                    ->disk('public')
                    ->directory('employee-contracts')
                    ->visibility('public')
                    ->multiple()
                    ->reorderable()
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->visible(fn (?EmployeeContract $record): bool => filled($record?->termination_document_id))
                    ->columnSpanFull(),

                Forms\Components\FileUpload::make('signed_document')
                    ->label(fn (?EmployeeContract $record): string => filled($record?->document_id)
                        ? 'Replace signed document'
                        : 'Signed document')
                    ->helperText(UploadLimits::withExistingNote(
                        'Required when the contract is already signed (paper copy). PDF, Word (.doc, .docx), JPEG, or PNG. The file is stored on SharePoint and Dokobit is skipped.',
                    ))
                    ->acceptedFileTypes([
                        'application/pdf',
                        'image/jpeg',
                        'image/png',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        '.doc',
                        '.docx',
                    ])
                    ->disk('public')
                    ->directory('employee-contracts')
                    ->visibility('public')
                    ->maxSize(UploadLimits::MAX_KILOBYTES)
                    ->downloadable()
                    ->openable()
                    ->required(fn (Get $get, ?EmployeeContract $record): bool => $get('status') === EmployeeContractStatus::Signed->value
                        && blank($record?->document_id))
                    ->visible(fn (Get $get, ?EmployeeContract $record): bool => $get('status') === EmployeeContractStatus::Signed->value
                        || filled($record?->document_id))
                    ->columnSpanFull(),
            ])
            ->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('employee.fullName')
                    ->label('Employee')
                    ->getStateUsing(fn (EmployeeContract $record): string => $record->employee?->fullName() ?? '—')
                    ->searchable(['employee.name', 'employee.surname'])
                    ->sortable(['employee.surname', 'employee.name']),

                Tables\Columns\TextColumn::make('sign_date')
                    ->label('Sign date')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('effective_date_from')
                    ->label('Effective from')
                    ->date()
                    ->sortable(),

                Tables\Columns\TextColumn::make('valid_to')
                    ->label('Valid to')
                    ->date()
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('base_salary')
                    ->label('Base salary')
                    ->money(Money::currency())
                    ->sortable(),

                Tables\Columns\TextColumn::make('default_bonus')
                    ->label('Default bonus')
                    ->money(Money::currency())
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('state_percentage')
                    ->label('State %')
                    ->suffix('%')
                    ->placeholder('—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (EmployeeContract $record): string => match ($record->status) {
                        EmployeeContractStatus::Draft => 'gray',
                        EmployeeContractStatus::Ready => 'warning',
                        EmployeeContractStatus::Signed => 'success',
                        EmployeeContractStatus::Inactive => 'danger',
                        default => 'gray',
                    })
                    ->getStateUsing(fn (EmployeeContract $record): string => $record->status?->label() ?? '—')
                    ->sortable(),

                Tables\Columns\TextColumn::make('document.name')
                    ->label('Document')
                    ->placeholder('—')
                    ->url(fn (EmployeeContract $record): ?string => $record->document?->displayFileUrl())
                    ->openUrlInNewTab()
                    ->toggleable(),

                Tables\Columns\TextColumn::make('termination_date')
                    ->label('Termination date')
                    ->date()
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextColumn::make('terminationDocument.name')
                    ->label('Termination document')
                    ->html()
                    ->getStateUsing(function (EmployeeContract $record): string {
                        $document = $record->terminationDocument;
                        if ($document === null) {
                            return '—';
                        }

                        $links = $document->attachedFileLinks();
                        if ($links === []) {
                            $url = $document->displayFileUrl();
                            $label = e($document->name ?: 'Termination document');

                            return $url
                                ? '<a href="'.e($url).'" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">'.$label.'</a>'
                                : $label;
                        }

                        $items = [];
                        foreach ($links as $file) {
                            $name = e((string) ($file['name'] ?? 'file'));
                            $url = filled($file['url'] ?? null) ? e((string) $file['url']) : null;
                            $items[] = $url !== null
                                ? '<a href="'.$url.'" target="_blank" rel="noopener noreferrer" class="text-primary-600 hover:underline dark:text-primary-400">'.$name.'</a>'
                                : $name;
                        }

                        return implode('<br>', $items);
                    })
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('effective_date_from', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('employee_id')
                    ->label('Employee')
                    ->relationship(
                        'employee',
                        'name',
                        fn (Builder $query) => $query->orderBy('surname')->orderBy('name'),
                    )
                    ->getOptionLabelFromRecordUsing(
                        fn (Employee $employee): string => $employee->fullName(),
                    )
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                EmployeeContractSignAction::make(),
                EmployeeContractEndAction::make(),
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
        return parent::getEloquentQuery()->with(['employee', 'document', 'terminationDocument']);
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
            'index' => Pages\ListEmployeeContracts::route('/'),
            'create' => Pages\CreateEmployeeContract::route('/create'),
            'edit' => Pages\EditEmployeeContract::route('/{record}/edit'),
        ];
    }

    public static function overlapWarningFromForm(Get $get, ?EmployeeContract $record = null): ?string
    {
        return EmployeeContract::overlapWarningMessage(
            employeeId: (int) ($get('employee_id') ?? 0),
            effectiveFrom: $get('effective_date_from'),
            validTo: $get('valid_to'),
            ignoreId: $record?->getKey() ? (int) $record->getKey() : null,
        );
    }

    public static function warnIfOverlapping(Get $get, ?EmployeeContract $record = null): void
    {
        $message = static::overlapWarningFromForm($get, $record);

        if ($message === null) {
            return;
        }

        Notification::make()
            ->title('Overlapping contract')
            ->body($message)
            ->warning()
            ->persistent()
            ->send();
    }

    public static function notifyOverlapOnSave(EmployeeContract $record): void
    {
        $message = EmployeeContract::overlapWarningMessage(
            employeeId: (int) $record->employee_id,
            effectiveFrom: $record->effective_date_from,
            validTo: $record->valid_to,
            ignoreId: (int) $record->getKey(),
        );

        if ($message === null) {
            return;
        }

        Notification::make()
            ->title('Overlapping contract')
            ->body($message)
            ->warning()
            ->persistent()
            ->send();
    }

    public static function closeOverlapsIfSigned(EmployeeContract $record): int
    {
        $closed = $record->closeOverlappingContracts();

        if ($closed > 0) {
            Notification::make()
                ->title('Overlapping contracts updated')
                ->body('Set an end date on '.$closed.' overlapping contract'.($closed === 1 ? '' : 's').' for this employee.')
                ->success()
                ->send();
        }

        return $closed;
    }
}
