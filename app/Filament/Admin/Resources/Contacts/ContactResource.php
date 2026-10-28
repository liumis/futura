<?php

namespace App\Filament\Admin\Resources\Contacts;

use App\Filament\Admin\Pages\Invoices;
use App\Filament\Admin\Resources\Contacts\Pages\CreateContact;
use App\Filament\Admin\Resources\Contacts\Pages\EditContact;
use App\Filament\Admin\Resources\Contacts\Pages\ListContacts;
use App\Filament\Admin\Support\CompanyProfileSchema;
use App\Models\Contact;
use App\Models\Invoice;
use App\Models\OriginCountry;
use App\Services\ContactMerger;
use BackedEnum;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Throwable;
use UnitEnum;

class ContactResource extends Resource
{
    protected static ?string $model = Contact::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationLabel = 'Contacts';

    protected static ?string $modelLabel = 'Contact';

    protected static ?string $pluralModelLabel = 'Contacts';

    protected static string|UnitEnum|null $navigationGroup = 'Finances';

    protected static ?int $navigationSort = 6;

    public static function form(Schema $schema): Schema
    {
        return CompanyProfileSchema::configure($schema, forContact: true);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('company_name', 'asc')
            ->columns([
                Tables\Columns\TextInputColumn::make('company_name')
                    ->label('Company')
                    ->searchable()
                    ->sortable()
                    ->rules(['required', 'string', 'max:255']),

                Tables\Columns\TextInputColumn::make('company_id')
                    ->label('Company id')
                    ->searchable()
                    ->sortable()
                    ->rules(['required', 'string', 'max:255']),

                Tables\Columns\TextInputColumn::make('company_vat')
                    ->label('VAT code')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->rules(['nullable', 'string', 'max:255']),

                Tables\Columns\TextInputColumn::make('bank_account')
                    ->label('IBAN')
                    ->searchable()
                    ->placeholder('—')
                    ->rules(['nullable', 'string', 'max:34'])
                    ->toggleable(),

                Tables\Columns\TextInputColumn::make('bank_bic')
                    ->label('BIC')
                    ->searchable()
                    ->placeholder('—')
                    ->rules(['nullable', 'string', 'max:11'])
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\SelectColumn::make('company_country')
                    ->label('Country')
                    ->options(fn (): array => OriginCountry::options())
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->selectablePlaceholder(true)
                    ->rules(['nullable', 'string', 'max:255']),

                Tables\Columns\TextInputColumn::make('company_email')
                    ->label('Company email')
                    ->searchable()
                    ->placeholder('—')
                    ->rules(['nullable', 'email', 'max:255'])
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextInputColumn::make('contact_name')
                    ->label('Contact')
                    ->searchable()
                    ->sortable()
                    ->placeholder('—')
                    ->rules(['nullable', 'string', 'max:255'])
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\TextInputColumn::make('contact_email')
                    ->label('Contact email')
                    ->searchable()
                    ->placeholder('—')
                    ->rules(['nullable', 'email', 'max:255'])
                    ->toggleable(isToggledHiddenByDefault: true),

                Tables\Columns\ToggleColumn::make('private_provider')
                    ->label('Private')
                    ->sortable(),

                Tables\Columns\SelectColumn::make('default_finance_type')
                    ->label('Default category')
                    ->options(fn (): array => self::flatFinanceCategoryOptions())
                    ->placeholder('—')
                    ->searchable()
                    ->selectablePlaceholder(true)
                    ->toggleable(),

                Tables\Columns\TextColumn::make('invoices_count')
                    ->counts('invoices')
                    ->label('Invoices')
                    ->sortable()
                    ->alignEnd()
                    ->url(fn (Contact $record): ?string => ($record->invoices_count ?? 0) > 0
                        ? Invoices::getUrl([
                            'filters' => [
                                'month' => 'any',
                                'contact_id' => $record->getKey(),
                            ],
                        ])
                        : null)
                    ->color(fn (Contact $record): ?string => ($record->invoices_count ?? 0) > 0 ? 'primary' : null)
                    ->tooltip(fn (Contact $record): ?string => ($record->invoices_count ?? 0) > 0
                        ? 'Open all invoices for this contact'
                        : null),

                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkAction::make('mergeContacts')
                    ->label('Merge')
                    ->icon('heroicon-o-arrows-pointing-in')
                    ->color('warning')
                    ->button()
                    ->modalHeading('Merge selected providers?')
                    ->modalDescription(function (BulkAction $action): string {
                        $records = $action->getSelectedRecords();
                        $lines = $records
                            ->map(function (Contact $contact): string {
                                $invoices = (int) ($contact->invoices_count ?? $contact->invoices()->count());

                                return '• #'.$contact->getKey().' '.$contact->company_name
                                    .' ('.$invoices.' invoice'.($invoices === 1 ? '' : 's').')';
                            })
                            ->implode("\n");

                        return "Selected:\n{$lines}\n\n"
                            ."Invoices, payments, imports and filled profile fields move to the contact you keep.\n"
                            .'The other selected contacts are permanently deleted. This cannot be undone.';
                    })
                    ->modalSubmitActionLabel('Merge')
                    ->form(function (BulkAction $action): array {
                        /** @var Collection<int, Contact> $records */
                        $records = $action->getSelectedRecords();
                        $records->loadCount('invoices');
                        $options = $records
                            ->mapWithKeys(function (Contact $contact): array {
                                $invoices = (int) ($contact->invoices_count ?? 0);

                                return [
                                    (string) $contact->getKey() => '#'.$contact->getKey()
                                        .' · '.$contact->company_name
                                        .' · '.$invoices.' invoice'.($invoices === 1 ? '' : 's'),
                                ];
                            })
                            ->all();

                        $default = (string) ContactMerger::suggestTargetId($records);

                        return [
                            Select::make('target_id')
                                ->label('Keep this provider')
                                ->options($options)
                                ->default($default)
                                ->required()
                                ->native(false)
                                ->helperText('Missing fields on the kept contact are filled from the others when blank.'),
                        ];
                    })
                    ->deselectRecordsAfterCompletion()
                    ->action(function (Collection $records, array $data): void {
                        if ($records->count() < 2) {
                            Notification::make()
                                ->title('Select at least two providers')
                                ->warning()
                                ->send();

                            return;
                        }

                        $targetId = (int) ($data['target_id'] ?? 0);
                        $ids = $records->modelKeys();
                        if ($targetId <= 0 || ! in_array($targetId, array_map('intval', $ids), true)) {
                            Notification::make()
                                ->title('Choose which provider to keep')
                                ->warning()
                                ->send();

                            return;
                        }

                        $sources = array_values(array_filter(
                            array_map('intval', $ids),
                            fn (int $id): bool => $id !== $targetId,
                        ));

                        try {
                            $result = ContactMerger::mergeInto($targetId, $sources);
                            $target = Contact::query()->find($result['target_id']);

                            Notification::make()
                                ->title('Providers merged')
                                ->body(($target?->company_name ?? ('#'.$result['target_id']))
                                    .' · moved '.$result['invoices'].' invoice(s)'
                                    .(count($result['merged_ids']) > 0 ? ' · removed '.count($result['merged_ids']).' duplicate(s)' : ''))
                                ->success()
                                ->send();
                        } catch (Throwable $e) {
                            Notification::make()
                                ->title('Merge failed')
                                ->body($e->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription('Invoices linked to deleted contacts are kept and unlinked (no company/supplier).'),
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

    /**
     * Flat options for SelectColumn (grouped option arrays are not supported).
     *
     * @return array<string, string>
     */
    private static function flatFinanceCategoryOptions(): array
    {
        $options = [];

        foreach (Invoice::financeCategorySelectOptions() as $groupLabel => $group) {
            if (! is_array($group)) {
                continue;
            }

            foreach ($group as $value => $label) {
                $options[(string) $value] = $groupLabel.' · '.$label;
            }
        }

        return $options;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListContacts::route('/'),
            'create' => CreateContact::route('/create'),
            'edit' => EditContact::route('/{record}/edit'),
        ];
    }
}
