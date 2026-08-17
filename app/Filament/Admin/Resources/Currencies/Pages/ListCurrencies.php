<?php

namespace App\Filament\Admin\Resources\Currencies\Pages;

use App\Filament\Admin\Resources\Currencies\CurrencyResource;
use App\Models\Currency;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListCurrencies extends ListRecords
{
    protected static string $resource = CurrencyResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('importFromLbBank')
                ->label('Import from Lietuvos bankas')
                ->icon('heroicon-o-arrow-down-tray')
                ->requiresConfirmation()
                ->modalHeading('Import official rates')
                ->modalDescription('Fetch the latest euro foreign exchange reference rates published by Lietuvos bankas (LB).')
                ->action(function (): void {
                    try {
                        $count = Currency::importFromLbBank();

                        Notification::make()
                            ->success()
                            ->title('Rates imported')
                            ->body("Updated {$count} currencies from Lietuvos bankas.")
                            ->send();
                    } catch (\Throwable $e) {
                        Notification::make()
                            ->danger()
                            ->title('Import failed')
                            ->body('Could not import rates from Lietuvos bankas: '.$e->getMessage())
                            ->send();
                    }
                }),

            Actions\CreateAction::make(),
        ];
    }
}
