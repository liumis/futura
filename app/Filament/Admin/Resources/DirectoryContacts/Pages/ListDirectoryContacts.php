<?php

namespace App\Filament\Admin\Resources\DirectoryContacts\Pages;

use App\Enums\DirectoryContactPriority;
use App\Filament\Admin\Concerns\HasDoubleClickCellEdit;
use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Models\DirectoryContact;
use App\Models\DirectoryContactStage;
use App\Services\DirectoryContactExcelImporter;
use App\Support\UploadLimits;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListDirectoryContacts extends ListRecords
{
    use HasDoubleClickCellEdit;

    protected static string $resource = DirectoryContactResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importExcel')
                ->label('Import Excel')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->modalHeading('Import contacts from Excel')
                ->modalDescription('Creates or updates contact cards from a Futura CRM spreadsheet (Company name, Type, Country, Website, Email, Phone, Stage, Last contact, Contact person, etc.).')
                ->modalSubmitActionLabel('Import')
                ->form([
                    UploadLimits::apply(
                        FileUpload::make('file')
                            ->label('Excel file')
                            ->acceptedFileTypes([
                                '.xlsx',
                                '.xls',
                                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                                'application/vnd.ms-excel',
                            ])
                            ->disk('local')
                            ->directory('directory-contact-imports')
                            ->visibility('private')
                            ->required(),
                        'Futura CRM .xlsx with Company name column.',
                    ),
                ])
                ->action(function (array $data): void {
                    $this->importContactsFromExcel($data['file'] ?? null);
                }),
            Actions\CreateAction::make(),
        ];
    }

    protected function doubleClickCellEditRecord(string|int $key): ?Model
    {
        return DirectoryContact::query()->with('stage')->find($key);
    }

    /**
     * @return array<string, array{label: string, form: mixed, fill: \Closure, save: \Closure, can?: \Closure, description?: mixed}>
     */
    protected function doubleClickCellEditFields(): array
    {
        return [
            'company_name' => [
                'label' => 'Company name',
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Company name')
                    ->required()
                    ->maxLength(255),
                'fill' => fn (DirectoryContact $record): ?string => $record->company_name,
                'save' => function (DirectoryContact $record, mixed $value): void {
                    $name = (string) $this->doubleClickValidate($value, ['required', 'string', 'max:255']);
                    $record->update(['company_name' => $name]);
                },
            ],
            'type' => [
                'label' => 'Type',
                'form' => fn (): TextInput => TextInput::make('value')
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
                'fill' => fn (DirectoryContact $record): ?string => $record->type,
                'save' => function (DirectoryContact $record, mixed $value): void {
                    $type = $this->doubleClickValidate($value, ['nullable', 'string', 'max:255']);
                    $record->update(['type' => filled($type) ? trim((string) $type) : null]);
                },
            ],
            'segment' => [
                'label' => 'Segment',
                'form' => fn (): TextInput => TextInput::make('value')
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
                'fill' => fn (DirectoryContact $record): ?string => $record->segment,
                'save' => function (DirectoryContact $record, mixed $value): void {
                    $segment = $this->doubleClickValidate($value, ['nullable', 'string', 'max:255']);
                    $record->update(['segment' => filled($segment) ? trim((string) $segment) : null]);
                },
            ],
            'priority' => [
                'label' => 'Priority',
                'form' => fn (): Select => Select::make('value')
                    ->label('Priority')
                    ->options(DirectoryContactPriority::options())
                    ->native(false)
                    ->nullable(),
                'fill' => fn (DirectoryContact $record): ?string => $record->priority?->value,
                'save' => function (DirectoryContact $record, mixed $value): void {
                    if ($value === null || $value === '') {
                        $record->update(['priority' => null]);

                        return;
                    }

                    $priority = (string) $this->doubleClickValidate($value, [
                        'required',
                        'string',
                        'in:'.implode(',', array_column(DirectoryContactPriority::cases(), 'value')),
                    ]);
                    $record->update(['priority' => $priority]);
                },
            ],
            'website' => [
                'label' => 'Website',
                'form' => fn (): TextInput => TextInput::make('value')
                    ->label('Website')
                    ->prefix('https://')
                    ->placeholder('example.com')
                    ->maxLength(255),
                'fill' => fn (DirectoryContact $record): ?string => $record->website,
                'save' => function (DirectoryContact $record, mixed $value): void {
                    $state = trim((string) ($this->doubleClickValidate($value, ['nullable', 'string', 'max:255']) ?? ''));
                    if ($state === '') {
                        $record->update(['website' => null]);

                        return;
                    }

                    $state = preg_replace('#^https?://#i', '', $state) ?? $state;
                    $record->update(['website' => strtolower(rtrim($state, '/'))]);
                },
            ],
            'stage' => [
                'label' => 'Stage',
                'form' => fn (): Select => Select::make('value')
                    ->label('Stage')
                    ->options(
                        fn (): array => DirectoryContactStage::query()
                            ->orderBy('sort_order')
                            ->orderBy('id')
                            ->pluck('name', 'id')
                            ->all(),
                    )
                    ->searchable()
                    ->required()
                    ->native(false),
                'fill' => fn (DirectoryContact $record): ?int => $record->directory_contact_stage_id,
                'save' => function (DirectoryContact $record, mixed $value): void {
                    $stageId = (int) $this->doubleClickValidate($value, [
                        'required',
                        'integer',
                        'exists:directory_contact_stages,id',
                    ]);
                    $record->update(['directory_contact_stage_id' => $stageId]);
                },
            ],
        ];
    }

    public function importContactsFromExcel(mixed $path): void
    {
        if (is_array($path)) {
            $path = $path[0] ?? null;
        }

        $relative = is_string($path) ? $path : '';

        if ($relative === '' || ! Storage::disk('local')->exists($relative)) {
            Notification::make()
                ->title('No Excel file selected')
                ->danger()
                ->send();

            return;
        }

        $absolute = Storage::disk('local')->path($relative);

        try {
            $result = app(DirectoryContactExcelImporter::class)->import(
                $absolute,
                basename($relative),
                auth()->id(),
            );
        } catch (Throwable $exception) {
            Notification::make()
                ->title('Could not import contacts')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        } finally {
            Storage::disk('local')->delete($relative);
        }

        Notification::make()
            ->title('Contacts imported')
            ->body(sprintf(
                'Created %d, updated %d, persons %d, comments %d.',
                $result['created'],
                $result['updated'],
                $result['persons'],
                $result['comments'],
            ))
            ->success()
            ->send();
    }
}
