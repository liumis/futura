<?php

namespace App\Filament\Admin\Resources\DirectoryContacts\Pages;

use App\Filament\Admin\Resources\DirectoryContacts\DirectoryContactResource;
use App\Services\DirectoryContactExcelImporter;
use App\Support\UploadLimits;
use Filament\Actions;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;
use Throwable;

class ListDirectoryContacts extends ListRecords
{
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
