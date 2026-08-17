<?php

namespace App\Filament\Admin\Resources\InvoiceCodes\Pages;

use App\Filament\Admin\Resources\InvoiceCodes\InvoiceCodeResource;
use App\Services\InvoiceCodeImporter;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListInvoiceCodes extends ListRecords
{
    protected static string $resource = InvoiceCodeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importDefaults')
                ->label('Import default codes')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalHeading('Import default invoice codes')
                ->modalDescription('Import the standard Lithuanian invoice code list. Existing codes with the same code will be updated.')
                ->action(function (): void {
                    $result = InvoiceCodeImporter::importFromFile(database_path('data/invoice_codes.txt'));

                    Notification::make()
                        ->title('Invoice codes imported')
                        ->body($result['total'].' codes processed ('.$result['created'].' created, '.$result['updated'].' updated).')
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
