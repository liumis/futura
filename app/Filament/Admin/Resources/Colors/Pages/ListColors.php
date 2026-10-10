<?php

namespace App\Filament\Admin\Resources\Colors\Pages;

use App\Filament\Admin\Resources\Colors\ColorResource;
use App\Services\ColorHexFromImage;
use Filament\Actions;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListColors extends ListRecords
{
    protected static string $resource = ColorResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('fillHexFromImages')
                ->label('Fill hex from images')
                ->icon('heroicon-o-swatch')
                ->color('gray')
                ->requiresConfirmation()
                ->modalHeading('Fill real colors from swatch images?')
                ->modalDescription('Empty hex values will be estimated from each color’s image (center average). Existing hex values are left unchanged.')
                ->action(function (ColorHexFromImage $extractor): void {
                    $result = $extractor->fillAll(force: false);

                    Notification::make()
                        ->title('Real colors updated')
                        ->body("Updated {$result['updated']}. Skipped {$result['skipped']}. Missing image {$result['missing_image']}. Failed {$result['failed']}.")
                        ->success()
                        ->send();
                }),
            Actions\CreateAction::make(),
        ];
    }
}
